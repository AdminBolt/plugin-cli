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
 * Renders one of a plugin's panel pages, or runs one of its actions.
 *
 * Signs the viewer envelope the same way the panel does, then prints the
 * description the plugin returned as an outline of what the panel would draw.
 * Quicker than clicking through a panel, and it shows exactly what crossed
 * the wire.
 */
final class PageCommand extends Command
{
    public static function name(): string
    {
        return 'page';
    }

    public static function description(): string
    {
        return 'Render a plugin page, or run one of its actions';
    }

    public static function valueOptions(): array
    {
        return ['url', 'secret', 'path', 'panel', 'account', 'action', 'input', 'arguments', 'params'];
    }

    public function run(Input $input, Output $output): int
    {
        $slug = $input->argument(0);

        if ($slug === null) {
            $output->failure('Which page? For example: bolt-plugin page zones');

            return self::FAILURE;
        }

        $directory = $input->option('path') ?? getcwd() ?: '.';
        $secret = $input->option('secret') ?? $this->secretFromRuntimeFile($directory);

        if ($secret === null) {
            $output->failure('No hook secret. Pass --secret, or run this from a plugin directory that has a runtime.json.');

            return self::FAILURE;
        }

        $action = $input->option('action');
        $panel = $input->option('panel', 'client') ?? 'client';

        try {
            $body = Json::encode([
                'slug' => $slug,
                'panel' => $panel,
                'viewer' => ['id' => 1, 'name' => 'cli', 'username' => 'cli'],
                // An admin page is server-wide and has no account in scope,
                // which is exactly what the panel sends.
                'hosting_account' => $panel === 'admin' ? [] : [
                    'id' => 1,
                    'username' => $input->option('account', 'testaccount'),
                ],
                'action' => $action,
                'params' => Json::decode($input->option('params', '') ?? '', '--params'),
                'input' => Json::decode($input->option('input', '') ?? '', '--input'),
                'arguments' => Json::decode($input->option('arguments', '') ?? '', '--arguments'),
                'locale' => 'en',
            ]);
        } catch (\Throwable $e) {
            $output->failure($e->getMessage());

            return self::FAILURE;
        }

        $timestamp = time();
        $base = rtrim($input->option('url', 'http://127.0.0.1:8731') ?? '', '/');
        $url = $base . '/ui/' . rawurlencode($slug) . ($action !== null ? '/' . rawurlencode($action) : '');

        $headers = [
            Signature::HEADER_SIGNATURE => Signature::compute($secret, $timestamp, $body),
            Signature::HEADER_TIMESTAMP => (string) $timestamp,
            Signature::HEADER_CONTRACT => '1',
            'Content-Type' => 'application/json',
        ];

        $output->info(sprintf('POST %s', $url));

        try {
            $response = (new CurlHttpClient(timeout: 35, verifyTls: false))->send('POST', $url, $headers, $body);
        } catch (TransportException $e) {
            $output->failure($e->getMessage());
            $output->dim('  Is the plugin running? BOLT_PLUGIN_DIR="$PWD" php -S 127.0.0.1:8731 -t public public/index.php');

            return self::FAILURE;
        }

        $decoded = $response->json();

        if ($response->status === 401) {
            $output->failure('The plugin refused the signature. The secret here does not match the one in its runtime.json.');

            return self::FAILURE;
        }

        if ($response->status === 404) {
            $output->failure(sprintf('The plugin has no %s "%s".', $action !== null ? 'action' : 'page', $action ?? $slug));

            return self::FAILURE;
        }

        if (($decoded['status'] ?? null) !== 'ok') {
            $output->failure((string) ($decoded['message'] ?? 'The plugin could not render the page.'));

            return self::FAILURE;
        }

        if ($action !== null) {
            return $this->reportAction($output, $decoded['result'] ?? []);
        }

        $this->outline($output, $decoded['page'] ?? []);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function reportAction(Output $output, array $result): int
    {
        if (($result['errors'] ?? []) !== []) {
            $output->failure('The form was rejected:');

            foreach ($result['errors'] as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $output->bullet(sprintf('%s: %s', $field, $message));
                }
            }

            return self::FAILURE;
        }

        $message = (string) ($result['message'] ?? 'Done.');
        $level = (string) ($result['level'] ?? 'success');

        match ($level) {
            'danger' => $output->failure($message),
            'warning' => $output->warn($message),
            default => $output->success($message),
        };

        if (isset($result['redirect'])) {
            $output->bullet('redirects to ' . $result['redirect']);
        }

        if (($result['refresh'] ?? false) === true) {
            $output->dim('  The panel would re-render the page.');
        }

        return $level === 'danger' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Prints the shape of the page rather than raw JSON, so the author can
     * see what the panel would put on screen.
     *
     * @param array<string, mixed> $page
     */
    private function outline(Output $output, array $page): void
    {
        $output->success((string) ($page['heading'] ?? 'Untitled page'));

        if (isset($page['subheading'])) {
            $output->dim('  ' . $page['subheading']);
        }

        foreach ($page['stats'] ?? [] as $stat) {
            $output->bullet(sprintf(
                '%s: %s%s',
                $stat['label'] ?? '',
                $stat['value'] ?? '',
                isset($stat['description']) ? '  (' . $stat['description'] . ')' : ''
            ));
        }

        foreach ($page['header_actions'] ?? [] as $action) {
            $output->dim(sprintf('  [ %s ]  -> %s', $action['label'] ?? '', $action['name'] ?? ''));
        }

        foreach ($page['components'] ?? [] as $component) {
            $this->outlineComponent($output, $component, '  ');
        }

        if (isset($page['poll'])) {
            $output->dim(sprintf('  re-renders every %ds', $page['poll']));
        }
    }

    /** @param array<string, mixed> $component */
    private function outlineComponent(Output $output, array $component, string $indent): void
    {
        $type = (string) ($component['type'] ?? 'unknown');

        switch ($type) {
            case 'section':
                $output->heading((string) ($component['heading'] ?? 'Section'));

                foreach ($component['components'] ?? [] as $nested) {
                    $this->outlineComponent($output, $nested, $indent . '  ');
                }

                break;

            case 'table':
                $columns = array_map(static fn (array $c): string => (string) $c['label'], $component['columns'] ?? []);
                $rows = $component['rows'] ?? [];

                $output->heading(sprintf('Table (%d row%s)', count($rows), count($rows) === 1 ? '' : 's'));
                $output->line($indent . implode('  |  ', $columns));

                foreach (array_slice($rows, 0, 10) as $row) {
                    $cells = array_map(
                        static fn (array $c): string => (string) ($row[$c['key']] ?? ''),
                        $component['columns'] ?? []
                    );
                    $output->line($indent . implode('  |  ', $cells));
                }

                if (count($rows) > 10) {
                    $output->dim(sprintf('%s... and %d more', $indent, count($rows) - 10));
                }

                if ($rows === []) {
                    $output->dim($indent . ($component['empty']['message'] ?? 'empty'));
                }

                foreach ($component['row_actions'] ?? [] as $action) {
                    $output->dim(sprintf('%s[ %s ] per row  -> %s', $indent, $action['label'] ?? '', $action['name'] ?? ''));
                }

                break;

            case 'form':
                $output->heading('Form -> ' . ($component['action'] ?? ''));

                foreach ($component['fields'] ?? [] as $field) {
                    $output->line(sprintf(
                        '%s%-20s %s%s',
                        $indent,
                        $field['label'] ?? '',
                        $field['type'] ?? 'text',
                        ($field['required'] ?? false) ? '  required' : ''
                    ));
                }

                break;

            case 'alert':
                $output->line();
                $output->line(sprintf('%s[%s] %s', $indent, strtoupper((string) ($component['level'] ?? 'info')), $component['body'] ?? ''));

                break;

            case 'text':
                $output->line();
                $output->line($indent . ($component['content'] ?? ''));

                break;

            default:
                $output->dim($indent . $type);
        }
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
