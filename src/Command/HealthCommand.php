<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Http\CurlHttpClient;
use AdminBolt\Plugin\Support\Json;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Asks a running plugin what it is and which hooks it actually handles.
 *
 * Signed when a secret is available, because an unsigned probe deliberately
 * answers with nothing but "ok".
 */
final class HealthCommand extends Command
{
    public static function name(): string
    {
        return 'health';
    }

    public static function description(): string
    {
        return 'Probe a running plugin';
    }

    public static function valueOptions(): array
    {
        return ['url', 'secret', 'path'];
    }

    public function run(Input $input, Output $output): int
    {
        $url = rtrim($input->option('url', 'http://127.0.0.1:8731') ?? '', '/') . '/health';
        $directory = $input->option('path') ?? getcwd() ?: '.';
        $secret = $input->option('secret') ?? $this->secretFromRuntimeFile($directory);

        $headers = [];

        if ($secret !== null) {
            $timestamp = time();
            $headers = [
                Signature::HEADER_SIGNATURE => Signature::compute($secret, $timestamp, ''),
                Signature::HEADER_TIMESTAMP => (string) $timestamp,
            ];
        }

        try {
            $response = (new CurlHttpClient(timeout: 10, verifyTls: false))->send('GET', $url, $headers);
        } catch (TransportException $e) {
            $output->failure($e->getMessage());

            return self::FAILURE;
        }

        $decoded = $response->json();
        $output->write(Json::encode($decoded, pretty: true) . "\n");

        if ($response->status !== 200) {
            $output->failure(sprintf('HTTP %d from %s', $response->status, $url));

            return self::FAILURE;
        }

        if ($secret === null) {
            $output->success('The plugin is up.');
            $output->dim('  Pass --secret to see its version and the hooks it handles.');

            return self::SUCCESS;
        }

        $output->success(sprintf(
            '%s %s is up, handling %d hook%s.',
            $decoded['plugin']['name'] ?? 'plugin',
            $decoded['plugin']['version'] ?? '?',
            count($decoded['handled_hooks'] ?? []),
            count($decoded['handled_hooks'] ?? []) === 1 ? '' : 's'
        ));

        $declared = array_map(
            static fn (array $hook): string => $hook['event'],
            $decoded['plugin']['hooks'] ?? []
        );
        $unhandled = array_diff($declared, $decoded['handled_hooks'] ?? []);

        if ($unhandled !== []) {
            $output->warn('Declared but not handled: ' . implode(', ', $unhandled));
            $output->dim('  The panel will deliver these and the plugin will do nothing with them.');
        }

        return self::SUCCESS;
    }

    private function secretFromRuntimeFile(string $directory): ?string
    {
        $path = $directory . '/runtime.json';

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && is_string($decoded['hooks']['secret'] ?? null)
            ? $decoded['hooks']['secret']
            : null;
    }
}
