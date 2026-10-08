<?php

declare(strict_types=1);

namespace Sluis\Onnx;

use Sluis\Domain\PiiType;

/**
 * Which model, what its labels mean here, and how much text it may see at once.
 *
 * One is here. The multilingual NER model is 178MB as int8 and answers with three
 * kinds — person, place, organisation — which, next to a core that already reads
 * every format with a check digit, is most of what is left to find; run on Dutch
 * mail it finds them. Piiranha, trained on personal data itself, was the other
 * and was taken out: its int8 weights lost in quantisation nearly every name the
 * full weights find, and the full weights are over a gigabyte.
 *
 * The window is in characters rather than tokens on purpose: a token count is the
 * model's business and this has to be decided before the tokenizer is reached.
 * The default of 600 sits under a 256-token context with room for the tokenizer
 * to be worse at Dutch than at English.
 */
final readonly class Profile
{
    /**
     * @param  array<string, PiiType>  $labels  what the model's labels mean here
     * @param  int  $window  how many characters may go in at once
     * @param  float  $floor  the score below which an answer is not used
     */
    public function __construct(
        public string $model,
        public array $labels,
        public int $window = 600,
        public float $floor = 0.5,
        public string $quantisation = 'int8',
    ) {}

    /**
     * Person, place and organisation in ten languages, Dutch among them. 178MB as int8.
     *
     * BERT and not the smaller DistilBERT of the same name: the runtime has no
     * token classification for DistilBERT and refuses to load it.
     */
    public static function ner(): self
    {
        return new self(
            model: 'Xenova/bert-base-multilingual-cased-ner-hrl',
            labels: [
                'PER' => PiiType::Naam,
                'LOC' => PiiType::Stad,
                'ORG' => PiiType::Organisatie,
            ],
            window: 1200,
        );
    }

    /**
     * A label this version has no mapping for is still personal data the model
     * recognised, so it is masked as such rather than let through.
     */
    public function typeFor(string $label): PiiType
    {
        return $this->labels[strtoupper($label)] ?? PiiType::Onbekend;
    }
}
