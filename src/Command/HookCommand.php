<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Command;

use AdminBolt\Plugin\Exception\TransportException;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Http\CurlHttpClient;
use AdminBolt\Plugin\Support\Json;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

/**
 * Signs a fixture delivery and sends it to a running plugin.
 *
 * Uses the same signing code the panel uses, so a delivery that this accepts
 * is a delivery the panel would produce. The fastest way to see a handler run
 * without touching a real domain.
 */
final class HookCommand extends Command
{
    public static function name(): string
    {
        return 'hook';
    }

    public static function description(): string
    {
        return 'Sign a test hook delivery and send it to a running plugin';
    }

    public static function valueOptions(): array
    {
        return ['payload', 'context', 'url', 'secret', 'path', 'actor', 'timestamp'];
    }

    public function run(Input $input, Output $output): int
    {
        $hook = $input->argument(0);

        if ($hook === null) {
            $output->failure('Which hook? For example: bolt-plugin hook domain.creating');
            $output->dim('  Run "bolt-plugin hooks" for the catalogue.');

            return self::FAILURE;
        }

        if (!Hook::isKnown($hook)) {
            $output->failure(sprintf('"%s" is not a hook the panel dispatches.', $hook));
            $output->dim('  Run "bolt-plugin hooks" for the catalogue.');

            return self::FAILURE;
        }

        $directory = $input->option('path') ?? getcwd() ?: '.';
        $secret = $input->option('secret') ?? $this->secretFromRuntimeFile($directory);

        if ($secret === null) {
            $output->failure('No hook secret. Pass --secret, or run this from a plugin directory that has a runtime.json.');

            return self::FAILURE;
        }

        try {
            $payload = Json::decode($input->option('payload', '{}') ?? '{}', '--payload');
            $context = Json::decode($input->option('context', '') ?? '', '--context');
        } catch (\Throwable $e) {
            $output->failure($e->getMessage());

            return self::FAILURE;
        }

        if ($context === []) {
            // Almost every hook carries one, and a handler calling
            // clientFor() needs it, so supply a plausible default rather than
            // letting the plugin fail in a way the panel never would.
            $context = ['hosting_account' => ['id' => 1, 'username' => 'testaccount']];
        }

        $body = Json::encode([
            'hook' => $hook,
            'delivery_id' => 'dlv_cli_' . bin2hex(random_bytes(6)),
            'blocking' => Hook::isBlockable($hook),
            'attempt' => 1,
            'occurred_at' => gmdate('c'),
            'panel' => ['version' => 'cli', 'url' => 'https://panel.invalid'],
            'actor' => ['type' => $input->option('actor', 'admin'), 'id' => 1, 'username' => 'cli'],
            'context' => $context,
            'payload' => $payload,
        ]);

        $timestamp = (int) ($input->option('timestamp') ?? time());
        $url = rtrim($input->option('url', 'http://127.0.0.1:8731') ?? '', '/') . '/';

        $headers = Signature::headers($secret, $hook, $body, 'dlv_cli', $timestamp);

        $output->info(sprintf('POST %s  %s', $url, $hook));

        try {
            $response = (new CurlHttpClient(timeout: 35, verifyTls: false))->send('POST', $url, $headers, $body);
        } catch (TransportException $e) {
            $output->failure($e->getMessage());
            $output->dim('  Is the plugin running? BOLT_PLUGIN_DIR="$PWD" php -S 127.0.0.1:8731 -t public public/index.php');

            return self::FAILURE;
        }

        // The decision goes to stdout so it can be piped; everything else is
        // on stderr.
        $output->write(Json::encode($response->json(), pretty: true) . "\n");

        return $this->report($output, $response->status, $response->json());
    }

    /** @param array<mixed> $decoded */
    private function report(Output $output, int $status, array $decoded): int
    {
        if ($status === 401) {
            $output->failure('The plugin refused the signature. The secret here does not match the one in its runtime.json.');

            return self::FAILURE;
        }

        if ($status !== 200) {
            $output->failure(sprintf('The plugin answered HTTP %d.', $status));

            return self::FAILURE;
        }

        return match ($decoded['status'] ?? null) {
            'reject' => $this->rejected($output, (string) ($decoded['message'] ?? '')),
            'error' => $this->errored($output, (string) ($decoded['message'] ?? '')),
            default => $this->accepted($output, $decoded),
        };
    }

    private function accepted(Output $output, array $decoded): int
    {
        if (($decoded['mutations'] ?? []) !== []) {
            $output->success('Accepted, with mutations.');

            foreach ($decoded['mutations'] as $key => $value) {
                $output->bullet(sprintf('%s = %s', $key, is_scalar($value) ? (string) $value : Json::encode($value)));
            }

            return self::SUCCESS;
        }

        $output->success('Accepted. The operation would proceed unchanged.');

        return self::SUCCESS;
    }

    private function rejected(Output $output, string $message): int
    {
        $output->warn('Rejected. The operation would be refused and the user would see:');
        $output->bullet($message);

        // A rejection is a working plugin, so this is not a failure exit.
        return self::SUCCESS;
    }

    private function errored(Output $output, string $message): int
    {
        $output->failure('The plugin errored: ' . $message);
        $output->dim('  The panel would apply this hook\'s on_failure policy.');

        return self::FAILURE;
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
