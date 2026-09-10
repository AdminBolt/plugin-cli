<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Manifest;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Builds an installable tarball.
 *
 * Validates first, and refuses to package anything carrying credentials. A
 * runtime.json inside a distributed archive would hand every installer the
 * author's panel API secret.
 */
final class PackageCommand extends Command
{
    private const NEVER_PACKAGE = [
        'runtime.json',
        '.env',
        '.git',
        'var',
        'node_modules',
        '.github',
        '.phpunit.result.cache',
        '.DS_Store',
    ];

    public static function name(): string
    {
        return 'package';
    }

    public static function description(): string
    {
        return 'Build an installable tarball';
    }

    public static function valueOptions(): array
    {
        return ['path', 'out'];
    }

    public function run(Input $input, Output $output): int
    {
        $directory = $this->pluginDirectory($input);
        $manifestPath = $this->manifestPath($directory);

        if (!is_file($manifestPath)) {
            $output->failure(sprintf('No %s in %s.', Manifest::FILENAME, $directory));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        $errors = is_array($decoded) ? Manifest::validate($decoded) : ['plugin.json is not valid JSON.'];

        if ($errors !== []) {
            $output->failure('Not packaging an invalid plugin:');

            foreach ($errors as $error) {
                $output->bullet($error);
            }

            return self::FAILURE;
        }

        $manifest = Manifest::fromArray($decoded, $manifestPath);

        if (!is_file($directory . '/' . $manifest->entrypoint())) {
            $output->failure(sprintf('The entrypoint "%s" does not exist.', $manifest->entrypoint()));

            return self::FAILURE;
        }

        $outDirectory = rtrim($input->option('out', $directory . '/build') ?? '', '/');

        if (!is_dir($outDirectory) && !@mkdir($outDirectory, 0o755, true) && !is_dir($outDirectory)) {
            $output->failure(sprintf('Could not create %s.', $outDirectory));

            return self::FAILURE;
        }

        $archive = sprintf('%s/%s-%s.tar.gz', $outDirectory, $manifest->id, $manifest->version);

        $command = ['tar', '-czf', $archive];

        foreach (self::NEVER_PACKAGE as $exclude) {
            $command[] = '--exclude=' . $exclude;
        }

        // Exclude the build directory itself, or the archive tries to contain
        // itself when --out is left at the default.
        $command[] = '--exclude=' . basename($outDirectory);
        $command[] = '-C';
        $command[] = $directory;
        $command[] = '.';

        $escaped = implode(' ', array_map(escapeshellarg(...), $command));
        exec($escaped . ' 2>&1', $lines, $status);

        if ($status !== 0) {
            $output->failure('tar failed: ' . implode("\n", $lines));

            return self::FAILURE;
        }

        $output->success(sprintf('%s %s packaged.', $manifest->name, $manifest->version));
        $output->bullet($archive);
        $output->bullet($this->humanSize((int) filesize($archive)));

        if (!is_dir($directory . '/vendor')) {
            $output->warn('No vendor/ directory, so the archive has no dependencies in it.');
            $output->dim('  Run "composer install --no-dev" first if the target server cannot run composer.');
        }

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($bytes < 1024) {
                return sprintf('%d %s', $bytes, $unit);
            }

            $bytes = intdiv($bytes, 1024);
        }

        return sprintf('%d GB', $bytes);
    }
}
