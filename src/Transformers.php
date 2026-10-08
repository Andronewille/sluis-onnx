<?php

declare(strict_types=1);

namespace Sluis\Onnx;

use Codewithkyrian\Transformers\Pipelines\Pipeline as Model;
use Codewithkyrian\Transformers\Transformers as Runtime;
use RuntimeException;

use function Codewithkyrian\Transformers\Pipelines\pipeline;

/**
 * The real pipeline: TransformersPHP over ONNX Runtime, on the CPU, on this
 * machine. The model is read from a directory on disk and nothing is fetched:
 * a run that finds no weights stops and says which command puts them there.
 *
 * This class is the only one in Sluis that loads a model, and the only one that
 * has to be told where the models live. Everything it produces goes through
 * `Onnx`, which is what places the words and what the tests exercise.
 */
final class Transformers implements Pipeline
{
    /** What the runtime reads to load a model, and so what it would fetch if one were missing. */
    private const FILES = ['config.json', 'tokenizer.json', 'tokenizer_config.json', 'onnx/model_quantized.onnx'];

    private ?Model $pipeline = null;

    public function __construct(
        private readonly Profile $profile,
        private readonly string $models,
    ) {}

    public function __invoke(string $text): array
    {
        $entities = ($this->pipeline())($text, aggregationStrategy: 'average');

        if (! is_array($entities)) {
            throw new RuntimeException('The model did not answer with a list of what it found.');
        }

        return array_values(array_map($this->entity(...), $entities));
    }

    /**
     * One answer of the runtime, in the shape `Onnx` places. A missing word or
     * label reads as empty, as it always did; an answer that is not a list of
     * fields, or a field that is there and is not text, is refused, because the
     * only other thing to do with it is drop what the model found.
     *
     * @return array{entity_group: string, word: string, score: float}
     */
    private function entity(mixed $entity): array
    {
        if (! is_array($entity)) {
            throw new RuntimeException('The model answered in a shape Sluis does not read.');
        }

        $label = $entity['entity_group'] ?? $entity['entity'] ?? '';
        $word = $entity['word'] ?? '';
        $score = $entity['score'] ?? 1.0;

        if (! is_string($label) || ! is_string($word) || ! is_numeric($score)) {
            throw new RuntimeException('The model answered in a shape Sluis does not read.');
        }

        return ['entity_group' => $label, 'word' => $word, 'score' => (float) $score];
    }

    private function pipeline(): Model
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        $this->refuseToFetch();

        Runtime::setup()->setCacheDir($this->models)->apply();

        return $this->pipeline = pipeline('token-classification', $this->profile->model, quantized: true);
    }

    /**
     * Offline is a promise, so it is checked rather than hoped for: the weights
     * are either already here or this run does not happen. The alternative is a
     * masking run that opens a connection the first time it is used on a machine
     * nobody prepared — which is the one thing Sluis exists to prevent.
     *
     * Every file is checked, not the directory. The runtime has no offline switch:
     * it reads a file that is there and fetches one that is not, so a download
     * that stopped halfway leaves a directory that looks ready and is not.
     */
    private function refuseToFetch(): void
    {
        $directory = rtrim($this->models, '/').'/'.$this->profile->model;

        foreach (self::FILES as $file) {
            if (! is_file($directory.'/'.$file)) {
                throw new RuntimeException(
                    "The model is not on this machine: {$this->profile->model} has no {$file}. Sluis never "
                    ."fetches one. Run `vendor/bin/transformers download {$this->profile->model} "
                    ."token-classification --cache-dir={$this->models}` once, on a machine that may."
                );
            }
        }
    }
}
