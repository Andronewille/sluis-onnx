<?php

declare(strict_types=1);

namespace Sluis\Onnx\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sluis\Onnx\Profile;
use Sluis\Onnx\Transformers;

/**
 * The one class that loads a model, checked for the one thing that matters about
 * it: that it does not go and get one. These tests run without TransformersPHP
 * installed, which is the proof — the refusal happens before the runtime is
 * reached.
 */
class TransformersTest extends TestCase
{
    public function test_it_refuses_to_run_when_the_weights_are_not_on_this_machine(): void
    {
        $pipeline = new Transformers(Profile::ner(), sys_get_temp_dir().'/sluis-no-models');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sluis never fetches one');

        $pipeline('Karel woont hier.');
    }

    public function test_the_refusal_says_which_command_puts_them_there(): void
    {
        try {
            (new Transformers(Profile::ner(), '/models'))('Karel woont hier.');
            $this->fail('it went looking for a model');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vendor/bin/transformers download', $e->getMessage());
            $this->assertStringContainsString('bert-base-multilingual-cased-ner-hrl', $e->getMessage());
        }
    }

    /**
     * A directory is not a model. The runtime fetches whichever file it does not
     * find, so a download that stopped after the first file leaves something that
     * looks ready and would finish itself over the network in the middle of a mail.
     */
    public function test_it_refuses_a_model_that_is_only_half_on_this_machine(): void
    {
        $profile = Profile::ner();
        $models = sys_get_temp_dir().'/sluis-half-'.bin2hex(random_bytes(4));
        mkdir($models.'/'.$profile->model, 0700, true);
        file_put_contents($models.'/'.$profile->model.'/config.json', '{}');

        try {
            (new Transformers($profile, $models))('Karel woont hier.');
            $this->fail('it went looking for the rest of a model');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Sluis never fetches one', $e->getMessage());
            $this->assertStringContainsString('tokenizer.json', $e->getMessage());
        } finally {
            unlink($models.'/'.$profile->model.'/config.json');
            rmdir($models.'/'.$profile->model);
            rmdir(dirname($models.'/'.$profile->model));
            rmdir($models);
        }
    }
}
