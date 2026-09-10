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
        $output->dim('  Present participle. Run inside the operation, and can refuse it or');
        $output->dim('  change an allow-listed input.');
        $output->line();

        foreach (Hook::blockable() as $hook) {
            $mutable = Hook::mutableKeys($hook);

            $output->bullet($mutable === []
                ? $hook
                : sprintf('%s  (can change: %s)', $hook, implode(', ', $mutable)));
        }

        $output->heading('Notification hooks');
        $output->dim('  Past participle. Run once the operation succeeded. Queued and retried.');
        $output->dim('  Cannot change anything.');
        $output->line();

        // 17 of them, so group by resource rather than printing one flat list.
        $grouped = [];

        foreach (Hook::notifications() as $hook) {
            $grouped[Hook::resource($hook)][] = $hook;
        }

        foreach ($grouped as $resource => $hooks) {
            $output->bullet(sprintf('%-14s %s', $resource, implode('  ', array_map(
                static fn (string $hook): string => substr($hook, strlen($resource) + 1),
                $hooks
            ))));
        }

        $output->heading('Plugin lifecycle');
        $output->line();

        foreach (Hook::lifecycle() as $hook) {
            $output->bullet($hook);
        }

        $output->line();
        $output->dim('  Payloads: https://github.com/AdminBolt/plugin-sdk/blob/main/docs/hooks.md');

        return self::SUCCESS;
    }
}
