<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Scaffolds a plugin that runs, has a passing test suite, and validates.
 *
 * A scaffold that needs three edits before it does anything teaches nothing.
 * This one answers a hook the moment it is served.
 */
final class NewCommand extends Command
{
    public static function name(): string
    {
        return 'new';
    }

    public static function description(): string
    {
        return 'Scaffold a new plugin';
    }

    public static function valueOptions(): array
    {
        return ['vendor', 'namespace', 'author', 'description', 'path'];
    }

    public function run(Input $input, Output $output): int
    {
        $id = $input->argument(0);

        if ($id === null) {
            $output->failure('What is it called? For example: bolt-plugin new cloudflare-dns');

            return self::FAILURE;
        }

        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $id) !== 1) {
            $output->failure(sprintf('"%s" is not a usable plugin id.', $id));
            $output->dim('  Lower-case kebab-case, for example "cloudflare-dns". It becomes the install');
            $output->dim('  directory and the API key label, so it cannot contain spaces, dots or slashes.');

            return self::FAILURE;
        }

        $directory = rtrim($input->option('path', $id) ?? $id, '/');

        if (is_dir($directory) && (array) @scandir($directory) !== ['.', '..']) {
            $output->failure(sprintf('%s already exists and is not empty.', $directory));

            return self::FAILURE;
        }

        $namespace = $input->option('namespace')
            ?? $this->namespaceFor($input->option('vendor', 'acme') ?? 'acme', $id);

        $replacements = [
            '{{id}}' => $id,
            '{{name}}' => $this->titleCase($id),
            '{{vendor}}' => $input->option('vendor', 'acme') ?? 'acme',
            '{{namespace}}' => $namespace,
            // A namespace separator is a legal character in PHP source and an
            // escape sequence in JSON, so composer.json needs it doubled.
            // Substituting the raw value there produces a composer.json that
            // will not parse.
            '{{namespace_json}}' => str_replace('\\', '\\\\', $namespace),
            '{{author}}' => $input->option('author', 'Your Name') ?? 'Your Name',
            '{{description}}' => $input->option('description', 'An AdminBolt panel plugin.') ?? 'An AdminBolt panel plugin.',
        ];

        $files = [
            'plugin.json.stub' => 'plugin.json',
            'composer.json.stub' => 'composer.json',
            'phpunit.xml.stub' => 'phpunit.xml',
            'README.md.stub' => 'README.md',
            'gitignore.stub' => '.gitignore',
            'public/index.php.stub' => 'public/index.php',
            'src/Handler/ExampleHandler.php.stub' => 'src/Handler/ExampleHandler.php',
            'tests/HandlerTest.php.stub' => 'tests/HandlerTest.php',
        ];

        foreach ($files as $stub => $target) {
            $contents = @file_get_contents($this->stubDirectory() . '/' . $stub);

            if ($contents === false) {
                $output->failure(sprintf('Missing stub "%s". The CLI install is incomplete.', $stub));

                return self::FAILURE;
            }

            $path = $directory . '/' . $target;

            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o755, true) && !is_dir(dirname($path))) {
                $output->failure(sprintf('Could not create %s.', dirname($path)));

                return self::FAILURE;
            }

            if (@file_put_contents($path, strtr($contents, $replacements)) === false) {
                $output->failure(sprintf('Could not write %s.', $path));

                return self::FAILURE;
            }
        }

        $output->success(sprintf('Created %s in %s/', $replacements['{{name}}'], $directory));

        $output->heading('Next');
        $output->bullet('cd ' . $directory);
        $output->bullet('composer install');
        $output->bullet('composer test');
        $output->bullet('bolt-plugin validate');
        $output->line();
        $output->dim('  Then edit src/Handler/ExampleHandler.php, and declare the hooks and');
        $output->dim('  api.scopes you actually need in plugin.json.');

        return self::SUCCESS;
    }

    private function stubDirectory(): string
    {
        return dirname(__DIR__, 2) . '/stubs';
    }

    private function titleCase(string $id): string
    {
        return implode(' ', array_map(ucfirst(...), explode('-', $id)));
    }

    private function namespaceFor(string $vendor, string $id): string
    {
        return $this->studly($vendor) . '\\' . $this->studly($id);
    }

    private function studly(string $value): string
    {
        return implode('', array_map(ucfirst(...), preg_split('/[-_ ]+/', $value) ?: []));
    }
}
