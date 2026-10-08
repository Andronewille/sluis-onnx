<?php

namespace Sluis\Onnx;

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

    private mixed $pipeline = null;

    public function __construct(
        private readonly Profile $profile,
        private readonly string $models,
    ) {}

    public function __invoke(string $text): array
    {
        return array_values(array_map(
            fn (array $entity) => [
                'entity_group' => (string) ($entity['entity_group'] ?? $entity['entity'] ?? ''),
                'word' => (string) ($entity['word'] ?? ''),
                'score' => (float) ($entity['score'] ?? 1.0),
            ],
            ($this->pipeline())($text, aggregationStrategy: 'average'),
        ));
    }

    private function pipeline(): mixed
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
