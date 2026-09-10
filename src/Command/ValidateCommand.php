<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Manifest;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Checks a plugin before the panel has to.
 *
 * Runs the SDK's own manifest validation, so what passes here is exactly what
 * the panel accepts, plus the filesystem checks the panel would only discover
 * at install time.
 */
final class ValidateCommand extends Command
{
    public static function name(): string
    {
        return 'validate';
    }

    public static function description(): string
    {
        return 'Validate plugin.json and the plugin layout';
    }

    public static function valueOptions(): array
    {
        return ['path'];
    }

    public function run(Input $input, Output $output): int
    {
        $directory = $this->pluginDirectory($input);
        $manifestPath = $this->manifestPath($directory);

        if (!is_file($manifestPath)) {
            $output->failure(sprintf('No %s in %s.', Manifest::FILENAME, $directory));
            $output->dim('  Run this from a plugin directory, or pass --path.');

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($manifestPath);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $output->failure(sprintf('%s is not valid JSON: %s', Manifest::FILENAME, json_last_error_msg()));

            return self::FAILURE;
        }

        $errors = Manifest::validate($decoded);

        if ($errors !== []) {
            $output->failure(sprintf('%s has %d problem%s:', Manifest::FILENAME, count($errors), count($errors) === 1 ? '' : 's'));

            foreach ($errors as $error) {
                $output->bullet($error);
            }

            return self::FAILURE;
        }

        $manifest = Manifest::fromArray($decoded, $manifestPath);
        $warnings = $this->inspect($directory, $manifest);

        $output->success(sprintf('%s %s is valid.', $manifest->name, $manifest->version));

        $output->heading('Plugin');
        $output->bullet('id: ' . $manifest->id);
        $output->bullet('transport: ' . $manifest->transport());
        $output->bullet('entrypoint: ' . $manifest->entrypoint());

        $output->heading('Hooks');

        if ($manifest->hooks() === []) {
            $output->bullet('none declared');
        }

        foreach ($manifest->hooks() as $hook) {
            $output->bullet(sprintf(
                '%s%s',
                $hook['event'],
                $hook['blocking'] ? sprintf(' (blocking, %ds)', $hook['timeout']) : ''
            ));
        }

        if ($manifest->ui() !== []) {
            $output->heading('Pages');

            foreach ($manifest->ui() as $page) {
                $output->bullet(sprintf(
                    '%-14s %s panel%s%s',
                    $page['slug'],
                    $page['panel'],
                    $page['group'] !== null ? '  under ' . $page['group'] : '',
                    $page['render'] === 'iframe' ? '  (iframe)' : ''
                ));
            }
        }

        $output->heading('API scopes');

        if ($manifest->scopes() === []) {
            $output->bullet('none: this plugin cannot call the panel');
        }

        foreach ($manifest->scopes() as $scope) {
            $output->bullet($scope);
        }

        if ($warnings !== []) {
            $output->heading('Warnings');

            foreach ($warnings as $warning) {
                $output->warn($warning);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Problems that do not make the manifest invalid but will bite later.
     *
     * @return list<string>
     */
    private function inspect(string $directory, Manifest $manifest): array
    {
        $warnings = [];

        if (!is_file($directory . '/' . $manifest->entrypoint())) {
            $warnings[] = sprintf('The entrypoint "%s" does not exist. The panel cannot serve this plugin.', $manifest->entrypoint());
        }

        if (!is_file($directory . '/composer.json')) {
            $warnings[] = 'No composer.json, so the SDK will not be installed on the target server.';
        }

        // A plugin that is told about things but has no way to act is usually
        // a forgotten scope, and one with no scopes at all cannot reach the
        // panel at all.
        $notifications = array_filter(
            $manifest->hookNames(),
            static fn (string $hook): bool => !Hook::isBlockable($hook) && !Hook::isLifecycle($hook)
        );

        if ($notifications !== [] && $manifest->scopes() === []) {
            $warnings[] = 'The plugin subscribes to notification hooks but declares no api.scopes, so it cannot act on what it is told about.';
        }

        foreach ($manifest->hooks() as $hook) {
            if ($hook['blocking'] && $hook['timeout'] > 10) {
                $warnings[] = sprintf(
                    '%s blocks for up to %d seconds. That is time a customer spends waiting; keep a blocking hook under a few seconds.',
                    $hook['event'],
                    $hook['timeout']
                );
            }
        }

        if ($manifest->get('panel') === null && $manifest->hookNames() !== []) {
            $warnings[] = 'No "panel" constraint. If a hook you use was added in a later release, the install will succeed and the hook will silently never fire.';
        }

        foreach ($manifest->ui() as $page) {
            if ($page['render'] !== 'iframe') {
                continue;
            }

            if ($page['path'] === null) {
                $warnings[] = sprintf('Page "%s" renders as an iframe but names no path for the panel to proxy.', $page['slug']);
            }
        }

        // A page that cannot read anything shows an empty screen, which looks
        // like a broken plugin rather than a missing scope.
        if ($manifest->ui() !== [] && $manifest->scopes() === []) {
            $warnings[] = 'The plugin has panel pages but declares no api.scopes, so a page cannot read anything to show.';
        }

        $gitignore = $directory . '/.gitignore';

        if (is_file($gitignore) && !str_contains((string) file_get_contents($gitignore), 'runtime.json')) {
            $warnings[] = 'runtime.json is not in .gitignore. It holds a live API secret.';
        }

        if (is_file($directory . '/runtime.json')) {
            $mode = @fileperms($directory . '/runtime.json');

            if ($mode !== false && ($mode & 0o077) !== 0) {
                $warnings[] = sprintf('runtime.json is mode %04o and holds an API secret; it should be 0600.', $mode & 0o777);
            }
        }

        foreach ($manifest->hooks() as $hook) {
            if (Hook::isBlockable($hook['event']) && !$hook['blocking']) {
                $warnings[] = sprintf(
                    '%s can run inside the operation, but is not marked blocking, so its answer is ignored and it cannot refuse anything.',
                    $hook['event']
                );
            }

            // The mistake this naming invites: subscribing to the past tense
            // when you meant the present one. It validates cleanly and the
            // plugin silently cannot veto, which is hard to debug later.
            $twin = Hook::blockableTwin($hook['event']);

            if ($twin !== null && !$manifest->subscribesTo($twin)) {
                $warnings[] = sprintf(
                    '%s fires after the operation has already succeeded, so it cannot refuse anything. If you meant to stop it, subscribe to %s instead.',
                    $hook['event'],
                    $twin
                );
            }
        }

        return $warnings;
    }
}
