<?php

declare(strict_types=1);

namespace Sluis\Onnx\Tests;

use Sluis\Onnx\Pipeline;

/**
 * The model's answers without the model: the word and the label, which is all a
 * token-classification pipeline gives back. What the adapter has to do with that
 * — find where the words were — is the part worth testing, and it needs no
 * weights to test.
 */
final class FakePipeline implements Pipeline
{
    /** @var list<string> every text it was handed, in order */
    public array $saw = [];

    /** @param array<int, list<array{entity_group: string, word: string, score?: float}>> $answers */
    public function __construct(private array $answers) {}

    /** @param list<array{entity_group: string, word: string, score?: float}> $entities */
    public static function answering(array $entities): self
    {
        return new self([array_map(
            fn (array $entity) => $entity + ['score' => 0.99],
            $entities,
        )]);
    }

    public function __invoke(string $text): array
    {
        $this->saw[] = $text;

        return $this->answers[count($this->saw) - 1] ?? [];
    }
}
