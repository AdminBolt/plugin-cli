<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Tests;

use AdminBolt\Plugin\Manifest;
use AdminBolt\PluginCli\Command\NewCommand;
use AdminBolt\PluginCli\Command\ValidateCommand;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * The scaffold has to produce a plugin that installs, validates and runs. A
 * broken template is worse than no template: it teaches the wrong shape and
 * costs an afternoon to debug.
 */
final class ScaffoldTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-plugin-cli-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            exec('rm -rf ' . escapeshellarg($this->directory));
        }
    }

    private function scaffold(array $argv = []): int
    {
        return (new NewCommand())->run(
            new Input([...$argv, '--path', $this->directory], NewCommand::valueOptions()),
            $this->silentOutput(),
        );
    }

    private function silentOutput(): Output
    {
        $null = fopen('/dev/null', 'wb');

        return new Output($null, $null, decorated: false);
    }

    public function test_it_produces_a_valid_manifest(): void
    {
        self::assertSame(0, $this->scaffold(['wave-dns']));

        $manifest = json_decode((string) file_get_contents($this->directory . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], Manifest::validate($manifest));
        self::assertSame('wave-dns', $manifest['id']);
        self::assertSame('Wave Dns', $manifest['name']);
    }

    /**
     * A namespace separator is legal in PHP source and an escape sequence in
     * JSON. Substituting the raw value into composer.json produced a file
     * composer refused to parse, which broke every scaffolded plugin at the
     * first "composer install".
     */
    public function test_the_generated_composer_json_parses(): void
    {
        $this->scaffold(['wave-dns', '--vendor', 'acme']);

        $composer = json_decode((string) file_get_contents($this->directory . '/composer.json'), true);

        self::assertIsArray($composer, 'composer.json is not valid JSON.');
        self::assertSame(['Acme\\WaveDns\\' => 'src/'], $composer['autoload']['psr-4']);
        self::assertSame('acme/wave-dns', $composer['name']);
    }

    public function test_the_generated_php_uses_a_single_backslash_namespace(): void
    {
        $this->scaffold(['wave-dns', '--vendor', 'acme']);

        $handler = (string) file_get_contents($this->directory . '/src/Handler/ExampleHandler.php');

        self::assertStringContainsString('namespace Acme\\WaveDns\\Handler;', $handler);
        self::assertStringNotContainsString('Acme\\\\WaveDns', $handler);
    }

    public function test_every_generated_php_file_is_syntactically_valid(): void
    {
        $this->scaffold(['wave-dns']);

        foreach (['public/index.php', 'src/Handler/ExampleHandler.php', 'tests/HandlerTest.php'] as $file) {
            exec(sprintf('php -l %s 2>&1', escapeshellarg($this->directory . '/' . $file)), $out, $status);

            self::assertSame(0, $status, $file . ' has a syntax error: ' . implode("\n", $out));
        }
    }

    public function test_the_scaffold_passes_its_own_validation(): void
    {
        $this->scaffold(['wave-dns']);

        $exit = (new ValidateCommand())->run(
            new Input(['--path', $this->directory], ValidateCommand::valueOptions()),
            $this->silentOutput(),
        );

        self::assertSame(0, $exit);
    }

    public function test_the_secret_bearing_runtime_file_is_gitignored(): void
    {
        $this->scaffold(['wave-dns']);

        self::assertStringContainsString('runtime.json', (string) file_get_contents($this->directory . '/.gitignore'));
    }

    public function test_an_unusable_id_is_refused(): void
    {
        foreach (['Wave DNS', 'wave_dns', '../escape', '9lives'] as $id) {
            self::assertSame(1, $this->scaffold([$id]), sprintf('"%s" should have been refused.', $id));
        }
    }

    public function test_it_refuses_to_overwrite_a_non_empty_directory(): void
    {
        mkdir($this->directory, 0o755, true);
        file_put_contents($this->directory . '/important.txt', 'do not lose me');

        self::assertSame(1, $this->scaffold(['wave-dns']));
        self::assertFileExists($this->directory . '/important.txt');
    }

    public function test_a_custom_namespace_is_honoured(): void
    {
        $this->scaffold(['wave-dns', '--namespace', 'Wave\\Dns\\Plugin']);

        $composer = json_decode((string) file_get_contents($this->directory . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertSame(['Wave\\Dns\\Plugin\\' => 'src/'], $composer['autoload']['psr-4']);
    }
}
