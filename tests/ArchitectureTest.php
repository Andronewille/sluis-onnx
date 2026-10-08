<?php

declare(strict_types=1);

namespace Sluis\Onnx\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The promises this package makes, checked here rather than in the core's suite.
 * A rule that reaches across a package boundary fails the moment that package is
 * absent, and a rule that tolerates the absence stops checking anything — so each
 * package holds itself to what it promises.
 */
class ArchitectureTest extends TestCase
{
    /** No host is named anywhere: the adapter reads a directory, never a hub. */
    public function test_it_names_no_host(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('#https?://#', $source, "{$path} names a host");
        }
    }

    /**
     * Nothing is written anywhere but the caller's output. A model adapter is where
     * the temptation is worst: what it found, what it scored, what it could not
     * place — all of it is what was in the text.
     */
    public function test_nothing_writes_what_it_found(): void
    {
        foreach ($this->sources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(error_log|var_dump|print_r|var_export|syslog|trigger_error|echo)\s*\(?/',
                $source,
                "{$path} writes somewhere that is not the caller's output",
            );
        }
    }

    /** @return iterable<string, string> */
    private function sources(): iterable
    {
        $files = glob(dirname(__DIR__).'/src/*.php') ?: [];

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            yield basename($file) => php_strip_whitespace($file);
        }
    }
}
