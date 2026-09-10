<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Console;

/**
 * Writes to the terminal, with colour only when a terminal is attached.
 *
 * Everything informational goes to stderr and only the actual result goes to
 * stdout, so `bolt-plugin hook ... | jq` works.
 */
final class Output
{
    private readonly bool $decorated;

    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
        ?bool $decorated = null,
    ) {
        $this->decorated = $decorated ?? (function_exists('posix_isatty') && @posix_isatty($this->stderr));
    }

    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stderr, $text . "\n");
    }

    public function success(string $text): void
    {
        $this->line($this->paint('  ok  ', '42;30') . ' ' . $text);
    }

    public function failure(string $text): void
    {
        $this->line($this->paint(' fail ', '41;37') . ' ' . $text);
    }

    public function warn(string $text): void
    {
        $this->line($this->paint(' warn ', '43;30') . ' ' . $text);
    }

    public function info(string $text): void
    {
        $this->line($this->paint('info', '36') . '  ' . $text);
    }

    public function bullet(string $text): void
    {
        $this->line('  - ' . $text);
    }

    public function heading(string $text): void
    {
        $this->line();
        $this->line($this->paint($text, '1'));
    }

    public function dim(string $text): void
    {
        $this->line($this->paint($text, '2'));
    }

    private function paint(string $text, string $code): string
    {
        return $this->decorated ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
