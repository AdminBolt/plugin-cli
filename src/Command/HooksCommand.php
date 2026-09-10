<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Prints the hook catalogue, read from the SDK, so it cannot drift from what
 * the panel actually dispatches.
 */
final class HooksCommand extends Command
{
    public static function name(): string
    {
        return 'hooks';
    }

    public static function description(): string
    {
        return 'List every hook the panel dispatches';
    }

    public function run(Input $input, Output $output): int
    {
        $output->heading('Blocking hooks');
        $output->dim('  Run inside the operation. Can refuse it, or change an allow-listed input.');
        $output->line();

        foreach (Hook::before() as $hook) {
            $mutable = Hook::mutableKeys($hook);

            $output->bullet($mutable === []
                ? $hook
                : sprintf('%s  (can change: %s)', $hook, implode(', ', $mutable)));
        }

        $output->heading('After hooks');
        $output->dim('  Run once the operation succeeded. Queued and retried. Cannot change anything.');
        $output->line();

        foreach (Hook::after() as $hook) {
            $output->bullet($hook);
        }

        $output->heading('Plugin lifecycle');
        $output->line();

        foreach ([Hook::PLUGIN_INSTALLED, Hook::PLUGIN_SETTINGS_UPDATED, Hook::PLUGIN_UNINSTALLED] as $hook) {
            $output->bullet($hook);
        }

        $output->line();
        $output->dim('  Payloads: https://github.com/AdminBolt/plugin-sdk/blob/main/docs/hooks.md');

        return self::SUCCESS;
    }
}
