# Restate PHP SDK — Common types

The types the [Restate](https://restate.dev) PHP SDK packages share: `Restate\Serde` and `Restate\JsonSerde`, `Restate\Target`, `Restate\TerminalError`, the `Restate\Exception` marker interface, and the JSON value mapping that turns handler payloads into DTOs and enums and back (`Restate\JsonValueMapper`, `Restate\Reflection\HandlerSerdes`). Handlers ([`restate/sdk`](https://github.com/restate-php/sdk)) and the ingress client ([`restate/ingress`](https://github.com/restate-php/ingress)) use the same classes, so a `Target` or a `TerminalError` means the same thing on both sides of a call.

> [!NOTE]
> A community-maintained SDK: not an official Restate SDK, and not supported by Restate.

You rarely require this package yourself: every other `restate/*` package requires it, at the same version. Class names do not depend on the package: `Restate\Target` is `Restate\Target` whichever package brought it.

## Installation

```bash
composer require restate/common
```

## Usage

```php
use Restate\Target;
use Restate\TerminalError;

$target = Target::object('Counter', 'alice', 'add')->scoped('tenant-1');

// Thrown from a handler: Restate does not retry it, and the caller receives it.
throw new TerminalError('Unknown product', 404, ['product' => 'book']);
```

## Requirements

- PHP 8.4, 8.5 or 8.6
- No PHP extension besides `ext-json`; the Restate extension is not needed

## Documentation

See the [documentation](https://github.com/restate-php/restate/blob/main/docs/README.md), and [Package Split](https://github.com/restate-php/restate/blob/main/docs/decisions/0029-package-split.md) for what each package contains.

This repository is a read-only subtree split of [restate-php/restate](https://github.com/restate-php/restate). Report issues and send pull requests there.

## License

MIT; see [LICENSE](LICENSE).
