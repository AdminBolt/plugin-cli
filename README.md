# bolt-plugin

Command line tool for building [AdminBolt](https://adminbolt.com) panel
plugins. Scaffold one, validate it, deliver test hooks to it, package it.

```bash
composer global require adminbolt/plugin-cli
```

Make sure `~/.composer/vendor/bin` (or `~/.config/composer/vendor/bin`) is on
your `PATH`.

## Scaffold

```bash
bolt-plugin new cloudflare-dns --vendor=acme --author="Acme Ltd"
cd cloudflare-dns
composer install
composer test
```

What comes out runs, validates and has a passing test suite before you change
a line. Replace the handler in `src/Handler/`, then declare the hooks and API
scopes you actually need in `plugin.json`.

## Validate

```bash
bolt-plugin validate
```

Runs the SDK's own manifest validation, so what passes here is exactly what
the panel accepts. It also reports the things that are legal but will bite:

- an entrypoint the manifest names but that does not exist
- notification hooks with no API scopes, so the plugin cannot act on what it is
  told about
- panel pages with no API scopes, so a page has nothing it can read to show
- an iframe page that names no path for the panel to proxy
- a blocking hook subscribed but not marked blocking, so its answer is
  ignored and it can refuse nothing
- a blocking timeout long enough that a customer notices
- no `panel` version constraint, so a hook added in a later release silently
  never fires
- `runtime.json` not gitignored, or not mode 0600, while holding an API secret

Worth having in CI.

## Deliver a test hook

```bash
BOLT_PLUGIN_DIR="$PWD" php -S 127.0.0.1:8731 -t public public/index.php &

bolt-plugin hook domain.creating --payload='{"domain":"example.test"}'
```

```
info  POST http://127.0.0.1:8731/  domain.creating
{
    "status": "reject",
    "message": "example.test cannot be hosted here."
}
 warn  Rejected. The operation would be refused and the user would see:
  - example.test cannot be hosted here.
```

The delivery is signed with the same code the panel signs with, so a delivery
this tool produces is one the panel would produce. The secret is read from
`runtime.json` in the working directory, or passed with `--secret`.

The decision goes to stdout and everything else to stderr, so it pipes:

```bash
bolt-plugin hook domain.created --payload='{"id":42}' | jq .status
```

| Option | Default |
| --- | --- |
| `--payload` | `{}` |
| `--context` | a plausible hosting account |
| `--url` | `http://127.0.0.1:8731` |
| `--secret` | read from `runtime.json` |
| `--actor` | `admin` |
| `--timestamp` | now, for testing the replay window |

## Render a page

```bash
bolt-plugin page zones --account=acme
```

```
info  POST http://127.0.0.1:8731/ui/zones
  ok   Cloudflare
  Zones mirrored for this account
  - Zones: 12
  - Pending: 1
  [ Sync now ]  -> sync

Table (12 rows)
  Domain  |  Status  |  Last sync
  example.com  |  live  |  2026-09-10T09:12:00+00:00
```

It prints the shape of the page rather than raw JSON, so you can see what the
panel would draw. The viewer envelope is signed the same way the panel signs
it, so what the plugin accepts here is what it will accept in the panel.

Run one of the page's actions with `--action`, and give it the values a button
or a form would have carried:

```bash
bolt-plugin page zones --action=purge --arguments='{"key":"example.com"}'
bolt-plugin page zones --action=save --input='{"api_token":"cf_live_x"}'
```

Use `--panel=admin` for an admin page, where there is no account in scope.

## Probe a plugin

```bash
bolt-plugin health --url=http://127.0.0.1:8731
```

Reports the version and which hooks it actually handles, and warns about hooks
the manifest declares that no handler answers. Those are the silent ones: the
panel keeps delivering and the plugin keeps acknowledging.

An unsigned probe deliberately answers with nothing but `{"status":"ok"}`, so
pass a secret to see the detail.

## The catalogue

```bash
bolt-plugin hooks
```

Prints every hook the panel dispatches, which ones can block, and which
payload keys each blocking hook is allowed to change. Read from the SDK, so it
cannot drift from what the panel does.

## Package

```bash
bolt-plugin package
```

Validates, then builds `build/<id>-<version>.tar.gz`. It refuses to package an
invalid plugin, and it never includes `runtime.json`, `.env`, `.git` or `var`.
A `runtime.json` inside a distributed archive would hand every installer the
author's panel API secret.

Run `composer install --no-dev` first if the target server cannot run
composer.

## Exit codes

`0` when the command did what was asked. A rejected hook is a `0`: the plugin
worked, and it said no. `1` for a real failure, including a refused signature
or a plugin that is not running.

## Documentation

- [Plugin structure](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/plugin-structure.md)
- [Hooks](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/hooks.md)
- [Calling the panel API](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/api.md)
- [plugin.json](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/manifest.md)

## License

MIT.
