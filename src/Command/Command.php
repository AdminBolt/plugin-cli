<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Manifest;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

abstract class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    abstract public static function name(): string;

    abstract public static function description(): string;

    /** @return list<string> option names that take a value */
    public static function valueOptions(): array
    {
        return [];
    }

    abstract public function run(Input $input, Output $output): int;

    /**
     * Finds the plugin directory: --path, or the first argument, or the
     * working directory.
     */
    protected function pluginDirectory(Input $input, int $argumentIndex = 0): string
    {
        $path = $input->option('path') ?? $input->argument($argumentIndex) ?? getcwd() ?: '.';

        return rtrim($path, '/');
    }

    protected function manifestPath(string $directory): string
    {
        return $directory . '/' . Manifest::FILENAME;
    }
}
