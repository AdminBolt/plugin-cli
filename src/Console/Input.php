<?php

declare(strict_types=1);

namespace AdminBolt\PluginCli\Console;

/**
 * Parses argv into arguments and --options.
 *
 * Supports --flag, --key=value and --key value. Enough for this CLI, and it
 * keeps the tool dependency-free.
 */
final class Input
{
    /** @var list<string> */
    private array $arguments = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @param list<string> $argv without the script name */
    public function __construct(array $argv, array $valueOptions = [])
    {
        for ($i = 0; $i < count($argv); $i++) {
            $token = $argv[$i];

            if (!str_starts_with($token, '--')) {
                $this->arguments[] = $token;
                continue;
            }

            $token = substr($token, 2);

            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', $token, 2);
                $this->options[$name] = $value;
                continue;
            }

            // "--url http://..." only when the option is known to take a
            // value, so "--force validate" does not swallow the next argument.
            if (in_array($token, $valueOptions, true) && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $this->options[$token] = $argv[++$i];
                continue;
            }

            $this->options[$token] = true;
        }
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) !== false;
    }
}
