<?php

namespace Sluis\Onnx;

/**
 * The seam over the model. It exists so that everything hard about this adapter —
 * turning words the model reports into places in the text — is testable without
 * 178MB of weights and an FFI build, and so that swapping the runtime later
 * changes one class.
 */
interface Pipeline
{
    /**
     * @return list<array{entity_group: string, word: string, score: float}>
     */
    public function __invoke(string $text): array;
}
