<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Tests;

use AdminBolt\PluginCli\Console\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function test_it_reads_arguments_and_equals_options(): void
    {
        $input = new Input(['before_domain_creation', '--payload={"domain":"a.com"}', '--verbose']);

        self::assertSame('before_domain_creation', $input->argument(0));
        self::assertSame('{"domain":"a.com"}', $input->option('payload'));
        self::assertTrue($input->flag('verbose'));
    }

    public function test_a_space_separated_value_is_read_only_for_options_that_take_one(): void
    {
        $input = new Input(['hook', '--url', 'http://localhost:8731'], ['url']);

        self::assertSame('http://localhost:8731', $input->option('url'));
        self::assertSame(['hook'], $input->arguments());
    }

    /**
     * Without the known-options list, "--force validate" would swallow
     * "validate" as the flag's value and lose the command argument.
     */
    public function test_a_flag_does_not_swallow_the_next_argument(): void
    {
        $input = new Input(['--force', 'validate'], ['url']);

        self::assertTrue($input->flag('force'));
        self::assertSame('validate', $input->argument(0));
    }

    public function test_missing_options_fall_back_to_the_default(): void
    {
        $input = new Input([]);

        self::assertSame('http://127.0.0.1:8731', $input->option('url', 'http://127.0.0.1:8731'));
        self::assertFalse($input->flag('anything'));
    }

    public function test_a_json_payload_containing_dashes_survives(): void
    {
        $input = new Input(['--payload={"content":"--not-an-option"}'], ['payload']);

        self::assertSame('{"content":"--not-an-option"}', $input->option('payload'));
    }
}
