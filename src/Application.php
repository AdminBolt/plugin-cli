<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\PluginCli\Command\Command;
use AdminBolt\PluginCli\Command\HealthCommand;
use AdminBolt\PluginCli\Command\HookCommand;
use AdminBolt\PluginCli\Command\HooksCommand;
use AdminBolt\PluginCli\Command\NewCommand;
use AdminBolt\PluginCli\Command\PackageCommand;
use AdminBolt\PluginCli\Command\ValidateCommand;
use AdminBolt\PluginCli\Console\Input;
use AdminBolt\PluginCli\Console\Output;

final class Application
{
    public const VERSION = '1.0.0';

    /** @var list<class-string<Command>> */
    private const COMMANDS = [
        NewCommand::class,
        ValidateCommand::class,
        HookCommand::class,
        HooksCommand::class,
        HealthCommand::class,
        PackageCommand::class,
    ];

    public function __construct(private readonly Output $output = new Output())
    {
    }

    /** @param list<string> $argv without the script name */
    public function run(array $argv): int
    {
        $name = $argv[0] ?? null;

        if ($name === null || in_array($name, ['help', '--help', '-h'], true)) {
            return $this->usage();
        }

        if (in_array($name, ['--version', '-V'], true)) {
            $this->output->line('bolt-plugin ' . self::VERSION);

            return Command::SUCCESS;
        }

        foreach (self::COMMANDS as $class) {
            if ($class::name() !== $name) {
                continue;
            }

            $input = new Input(array_slice($argv, 1), $class::valueOptions());

            try {
                return (new $class())->run($input, $this->output);
            } catch (PluginException $e) {
                // The SDK's exceptions are written to be read by a plugin
                // author, so print the message rather than a stack trace.
                $this->output->failure($e->getMessage());

                return Command::FAILURE;
            } catch (\Throwable $e) {
                $this->output->failure($e->getMessage());
                $this->output->dim(sprintf('  %s at %s:%d', $e::class, $e->getFile(), $e->getLine()));

                return Command::FAILURE;
            }
        }

        $this->output->failure(sprintf('Unknown command "%s".', $name));
        $this->usage();

        return Command::FAILURE;
    }

    private function usage(): int
    {
        $this->output->line('bolt-plugin ' . self::VERSION . ' - build AdminBolt panel plugins');
        $this->output->heading('Usage');
        $this->output->line('  bolt-plugin <command> [arguments] [--options]');
        $this->output->heading('Commands');

        foreach (self::COMMANDS as $class) {
            $this->output->line(sprintf('  %-10s %s', $class::name(), $class::description()));
        }

        $this->output->heading('Examples');
        $this->output->line('  bolt-plugin new cloudflare-dns --vendor=acme');
        $this->output->line('  bolt-plugin validate');
        $this->output->line('  bolt-plugin hook before_domain_creation --payload=\'{"domain":"example.test"}\'');
        $this->output->line('  bolt-plugin health --url=http://127.0.0.1:8731');
        $this->output->line('  bolt-plugin package');
        $this->output->line();
        $this->output->dim('  Docs: https://github.com/AdminBolt/plugin-sdk/tree/main/docs');

        return Command::SUCCESS;
    }
}
