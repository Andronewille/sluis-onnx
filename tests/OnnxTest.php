<?php

namespace Sluis\Onnx\Tests;

use PHPUnit\Framework\TestCase;
use Sluis\Domain\CannotPlace;
use Sluis\Domain\PiiType;
use Sluis\Domain\Spans;
use Sluis\Onnx\Onnx;
use Sluis\Onnx\Profile;
use Sluis\Sluis;

/**
 * The adapter, without the model. What is checked here is everything between the
 * model's answer and a span: the labels, the places, the seams and what happens
 * when a word cannot be found — which is the part that leaks if it is wrong.
 */
class OnnxTest extends TestCase
{
    public function test_it_turns_a_label_and_a_word_into_a_span_it_can_point_at(): void
    {
        $spans = $this->recognise('Karel woont in Haasterdam.', [
            ['entity_group' => 'PER', 'word' => 'Karel'],
            ['entity_group' => 'LOC', 'word' => 'Haasterdam'],
        ]);

        $this->assertCount(2, $spans);
        $this->assertSame([PiiType::Naam, PiiType::Stad], array_map(fn ($s) => $s->type, iterator_to_array($spans)));
        $this->assertSame([0, 15], array_map(fn ($s) => $s->start, iterator_to_array($spans)));
        $this->assertSame(['Karel', 'Haasterdam'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
    }

    /**
     * The second mention lands on the second mention. A pipeline that reports two
     * `Karel`s and no offsets is trusted for the order it reports them in, and
     * nothing else.
     */
    public function test_it_places_the_second_mention_of_a_name_on_the_second_mention(): void
    {
        $spans = $this->recognise('Karel belde. Later belde Karel weer.', [
            ['entity_group' => 'PER', 'word' => 'Karel'],
            ['entity_group' => 'PER', 'word' => 'Karel'],
        ]);

        $this->assertSame([0, 25], array_map(fn ($s) => $s->start, iterator_to_array($spans)));
    }

    /** A continuation the tokenizer marked is not in the text; the marks come off. */
    public function test_it_takes_the_tokenizers_marks_off_a_word(): void
    {
        $spans = $this->recognise('Hij woont aan de Maanstraat.', [
            ['entity_group' => 'STREET', 'word' => '##Maanstraat'],
        ]);

        $this->assertSame('Maanstraat', $spans->first()->text);
        $this->assertSame(17, $spans->first()->start);
    }

    /**
     * Whitespace the tokenizer lost is found again in the text, because the text
     * is what decides how the words really read.
     */
    public function test_it_finds_a_word_whose_spacing_the_tokenizer_changed(): void
    {
        $spans = $this->recognise('Bezoek de Maanstraat 123 vandaag.', [
            ['entity_group' => 'STREET', 'word' => 'Maanstraat123'],
        ]);

        $this->assertSame('Maanstraat 123', $spans->first()->text);
        $this->assertSame(10, $spans->first()->start);
    }

    /**
     * A word the model reports and the text does not hold stops the run. The two
     * alternatives are both worse: a guessed position masks the wrong words, and
     * silence leaves the value the model just recognised in the text.
     */
    public function test_it_refuses_to_guess_where_a_word_it_cannot_find_belongs(): void
    {
        $this->expectException(CannotPlace::class);
        $this->expectExceptionMessage('could not place');

        $this->recognise('Karel woont hier.', [['entity_group' => 'PER', 'word' => 'Sietske']]);
    }

    /** The message says the type and never the words: an exception gets logged. */
    public function test_the_refusal_says_nothing_about_what_it_found(): void
    {
        try {
            $this->recognise('Karel woont hier.', [['entity_group' => 'PER', 'word' => 'Sietske']]);
            $this->fail('it placed a word that is not there');
        } catch (CannotPlace $e) {
            $this->assertStringNotContainsString('Sietske', $e->getMessage());
        }
    }

    public function test_it_leaves_an_answer_the_model_is_unsure_about(): void
    {
        $pipeline = new FakePipeline([[['entity_group' => 'PER', 'word' => 'Karel', 'score' => 0.2]]]);

        $this->assertCount(0, (new Onnx($pipeline, Profile::ner()))->recognise('Karel woont hier.'));
    }

    /**
     * A label this version has no mapping for is masked as something unnamed,
     * never let through: the model said it was personal data.
     */
    public function test_a_label_it_does_not_know_is_still_masked(): void
    {
        $spans = $this->recognise('Zijn pas is AB1234.', [['entity_group' => 'PASSPORTNUM', 'word' => 'AB1234']]);

        $this->assertSame(PiiType::Onbekend, $spans->first()->type);
    }

    public function test_the_profile_reads_its_own_labels_in_any_case(): void
    {
        $profile = Profile::ner();

        $this->assertSame(PiiType::Naam, $profile->typeFor('PER'));
        $this->assertSame(PiiType::Organisatie, $profile->typeFor('org'));
        $this->assertSame(PiiType::Onbekend, $profile->typeFor('SOMETHING_NEW'));
    }

    /**
     * Long text goes in in pieces, and the offsets that come back are offsets into
     * the whole text. A window that reported its own offsets would mask the right
     * words in the wrong place, somewhere near the top of the mail.
     */
    public function test_it_reads_long_text_in_pieces_and_still_says_where_things_are(): void
    {
        $filler = str_repeat('De monteur komt langs en kijkt naar de ketel. ', 20);
        $text = $filler.'Tot slot belde Sietske nog.';
        $at = strpos($text, 'Sietske');

        $pipeline = new FakePipeline([[], [['entity_group' => 'PER', 'word' => 'Sietske', 'score' => 0.99]]]);
        $spans = (new Onnx($pipeline, new Profile('fake', ['PER' => PiiType::Naam], window: 600)))->recognise($text);

        $this->assertGreaterThan(1, count($pipeline->saw), 'the text went in whole');
        $this->assertCount(1, $spans);
        $this->assertSame($at, $spans->first()->start);
        $this->assertSame('Sietske', $spans->first()->text);
    }

    /** The windows overlap, so a name on a seam is seen whole at least once. */
    public function test_the_pieces_overlap(): void
    {
        $text = str_repeat('een twee drie vier vijf zes zeven acht negen tien. ', 30);
        $pipeline = new FakePipeline([]);

        (new Onnx($pipeline, new Profile('fake', [], window: 600)))->recognise($text);

        $ends = array_map(fn (string $piece) => strlen($piece), $pipeline->saw);
        $this->assertGreaterThan(strlen($text), array_sum($ends), 'the pieces do not overlap');
    }

    /**
     * The point of the whole arrangement: the model is another pair of eyes on the
     * same text, and the core still wins where it is the more specific claim.
     * `Maanstraat 123` is an address, not a person called Maanstraat.
     */
    public function test_the_model_joins_the_core_rather_than_replacing_it(): void
    {
        $pipeline = FakePipeline::answering([
            ['entity_group' => 'PER', 'word' => 'Sietske'],
            ['entity_group' => 'LOC', 'word' => 'Haasterdam'],
        ]);

        $sluis = Sluis::nederlands()->plus(new Onnx($pipeline, Profile::ner()));
        $masked = $sluis->mask('Sietske woont aan de Maanstraat 123 in Haasterdam. Bel 0612345678.');

        $this->assertSame(
            'naam1mask woont aan de adres1mask in stad1mask. Bel telefoon1mask.',
            $masked->text,
        );
    }

    /**
     * The cursor is an optimisation, not a contract. A pipeline that ever answers
     * out of order must not make the adapter throw on a word that is plainly there:
     * it lands on the first mention, and everything after it is masked anyway
     * because a value found once is masked everywhere.
     */
    public function test_it_still_places_a_word_the_model_reported_out_of_order(): void
    {
        $spans = $this->recognise('Sietske sprak met Bouwmeester.', [
            ['entity_group' => 'PER', 'word' => 'Bouwmeester'],
            ['entity_group' => 'PER', 'word' => 'Sietske'],
        ]);

        $this->assertCount(2, $spans);
        $this->assertSame(['Sietske', 'Bouwmeester'], array_map(fn ($s) => $s->text, iterator_to_array($spans)));
    }

    /** @param list<array{entity_group: string, word: string, score?: float}> $entities */
    private function recognise(string $text, array $entities): Spans
    {
        return (new Onnx(FakePipeline::answering($entities), Profile::ner()))->recognise($text);
    }

    /**
     * A SentencePiece model reports a word in pieces and a piece is often two
     * letters. The same two letters are inside other words, and the first run of
     * the real model put a mask in the middle of `Vorige` for the `Ge` of a town:
     * the text came back from the reverse path with the token still in it.
     */
    public function test_it_places_a_piece_at_the_start_of_a_word_before_inside_another(): void
    {
        $spans = $this->recognise('Vorige week was ik in Geertruidenberg.', [
            ['entity_group' => 'LOC', 'word' => 'Ge'],
        ]);

        $this->assertSame(22, $spans->first()->start);
        $this->assertSame('Geertruidenberg', $spans->first()->text);
    }

    /**
     * Half a word is never masked. A token glued to the letters that were left
     * cannot be restored, so the rest of the word goes with the piece the model
     * named — and what was masked comes back exactly as it was written.
     */
    public function test_a_piece_of_a_word_takes_the_whole_word_and_comes_back(): void
    {
        $text = 'Vorige week was ik in Geertruidenberg.';
        $sluis = Sluis::with(new Onnx(FakePipeline::answering([
            ['entity_group' => 'LOC', 'word' => 'truidenberg'],
        ]), Profile::ner()));

        $masked = $sluis->mask($text);

        $this->assertSame('Vorige week was ik in stad1mask.', $masked->text);
        $this->assertSame($text, $sluis->unmask($masked->text, $masked->vault)->text);
    }
}
