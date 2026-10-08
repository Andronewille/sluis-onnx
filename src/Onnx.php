<?php

namespace Sluis\Onnx;

use Sluis\Application\Ports\Recogniser;
use Sluis\Domain\CannotPlace;
use Sluis\Domain\Span;
use Sluis\Domain\Spans;

/**
 * A local model as a recogniser: the names, streets and towns that no pattern
 * matches and no list holds. `Haasterdam` is not a Dutch town and `Sietske
 * Bouwmeester-Oving` is in nobody's name list, and both have to leave the mail.
 *
 * Nothing here reaches the network. The weights are on disk before this runs —
 * fetched once, deliberately, by `vendor/bin/transformers download` — and if they
 * are not, this says so rather than quietly downloading a third of a gigabyte in
 * the middle of masking a mail. Offline is the promise the whole tool rests on.
 *
 * The hard part is that the pipeline reports the words it found and not where
 * they were: `['entity_group' => 'PER', 'word' => 'Karel']` and nothing more. A
 * masker needs offsets, so this places every word itself, in reading order, and
 * throws when it cannot. Guessing would put a mask over the wrong words; skipping
 * would leave the name the model just found sitting in the text.
 */
final readonly class Onnx implements Recogniser
{
    public function __construct(
        private Pipeline $pipeline,
        private Profile $profile,
    ) {}

    public function recognise(string $text): Spans
    {
        $spans = Spans::none();

        foreach ($this->windows($text) as $offset => $window) {
            $cursor = 0;

            foreach (($this->pipeline)($window) as $entity) {
                if (($entity['score'] ?? 1.0) < $this->profile->floor) {
                    continue;
                }

                $word = $this->word((string) $entity['word']);

                if ($word === '') {
                    continue;
                }

                [$at, $found] = $this->place($window, $word, $cursor, (string) $entity['entity_group']);

                $spans = $spans->with(new Span(
                    $this->profile->typeFor((string) $entity['entity_group']),
                    $offset + $at,
                    $found,
                    'model',
                    (float) ($entity['score'] ?? 1.0),
                ));

                $cursor = $at + strlen($found);
            }
        }

        return $spans->resolved();
    }

    /**
     * What the tokenizer left on the word. A WordPiece continuation comes back as
     * `##straat` and a SentencePiece one as `▁Maan`; neither is in the text.
     */
    private function word(string $word): string
    {
        return trim(str_replace(['##', "\u{2581}"], ['', ' '], $word));
    }

    /**
     * Where that word is, from the cursor onwards, so the second mention of a name
     * lands on the second mention and not back on the first.
     *
     * Falling back to the start of the text matters more than the cursor does. If
     * the pipeline ever answers out of order, searching only forwards throws on a
     * word that is plainly in the text; landing on the first mention instead costs
     * nothing, because a value found once is masked everywhere anyway.
     *
     * The search is loose about whitespace because the tokenizer is: a word the
     * model reports as `Maanstraat 123` can come back with the space lost or
     * doubled, and the text is what decides how it really reads. It is not loose
     * about anything else — an answer that cannot be found is an error.
     *
     * The strictest reading is tried first: as the text writes it and at the start
     * of a word, then in any case, and only then inside a word. A SentencePiece
     * model reports the pieces of a word one by one, and the `Ge` of
     * `Geertruidenberg` is also the end of `Vorige`.
     *
     * @return array{int, string}
     */
    private function place(string $text, string $word, int $cursor, string $label): array
    {
        $cursor = min($cursor, strlen($text));

        $loose = implode('\s*', array_map(
            fn (string $character) => preg_quote($character, '/'),
            preg_split('//u', preg_replace('/\s+/u', '', $word) ?? $word, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));

        foreach (["/(?<![\p{L}\p{N}_]){$loose}/u", "/(?<![\p{L}\p{N}_]){$loose}/iu", "/{$loose}/u", "/{$loose}/iu"] as $pattern) {
            foreach ([$cursor, 0] as $from) {
                if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
                    return $this->whole($text, $match[0][1], $match[0][0]);
                }
            }
        }

        throw CannotPlace::found($this->profile->typeFor($label), 'the model');
    }

    /**
     * The words that piece is part of, whole. A mask that starts or stops inside a
     * word cannot be put back: the token ends up glued to the letters left over,
     * and the reverse path only recognises a token that stands on its own. So half
     * a word is never masked — the half the model did not name goes with it.
     *
     * @return array{int, string}
     */
    private function whole(string $text, int $at, string $found): array
    {
        preg_match('/[\p{L}\p{N}_]*$/u', substr($text, 0, $at), $before);
        preg_match('/^[\p{L}\p{N}_]*/u', substr($text, $at + strlen($found)), $after);

        return [$at - strlen($before[0] ?? ''), ($before[0] ?? '').$found.($after[0] ?? '')];
    }

    /**
     * The text in pieces the model can hold, cut on a space so a name is never
     * cut in half, and overlapping so a name on a seam is seen whole at least
     * once. The offsets come back absolute; the duplicates a seam produces are
     * settled by `Spans::resolved()` along with everything else.
     *
     * @return array<int, string>
     */
    private function windows(string $text): array
    {
        if (strlen($text) <= $this->profile->window) {
            return [0 => $text];
        }

        $windows = [];
        $at = 0;
        $overlap = (int) max(60, $this->profile->window / 8);

        while ($at < strlen($text)) {
            $piece = substr($text, $at, $this->profile->window);

            if ($at + strlen($piece) < strlen($text)) {
                $break = strrpos($piece, ' ');

                if ($break !== false && $break > $this->profile->window / 2) {
                    $piece = substr($piece, 0, $break);
                }
            }

            $windows[$at] = $piece;

            if ($at + strlen($piece) >= strlen($text)) {
                break;
            }

            $at += max(1, strlen($piece) - $overlap);
        }

        return $windows;
    }
}
