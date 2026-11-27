# Just for Every Task

## Status

Accepted. Supersedes decisions 1 to 3 of [Just Recipes](0041-just-recipes.md) and amends decision 2 of [Development Environment](0001-dev-environment.md): every task is a just recipe, and `composer.json` has no scripts.

## Context

[0041](0041-just-recipes.md) moved the tasks with arguments or several steps to just recipes and kept Composer scripts as the entry points of the common tasks, several of them calling a recipe. That left two task lists, and the split between them leaked into CI:

- The image-based suites were recipes per server family (`fpm`, `frankenphp`, `frankenphp-worker`), with the PHP-FPM front door chosen by `RESTATE_PHP_TEST_SERVER`, while `php -S` had no recipe at all: it was `composer test:integration` and a Composer script per PHPUnit group (`test:failure`, `test:large-bodies`, `test:scale`).
- So a nightly job that runs one group on every server needed a command per matrix entry, or a shell branch on the server: `composer test:failure` for `php-cli`, `just test frankenphp --group failure-nightly` for FrankenPHP, `RESTATE_PHP_TEST_SERVER=<server> just test fpm --group failure-nightly` for the rest.
- Variants of one suite became scripts of their own: `test:integration:restate-1.7` (another Restate image), `test:integration:process` and `test:integration:external` (another Restate mode).
- Many scripts only forwarded to a recipe or a `bin/` program (`build:extension`, `build:pie`, `bench:extension`, `load`, `test:soak`, `test:serving-hazards`, `test:upstream`, `bench:compare`, `release:split`).

## Decision

1. **Every task is a just recipe**, and `composer.json` has no `scripts`. Composer installs the dependencies; just runs everything else:
   - the root `justfile`: `check`, `cs`, `cs-fix`, `analyse`, `lint`, `check-packages`, `serve`, `bench`, `bench-compare` and `split`;
   - `test` (`tests/justfile`): `unit`, `release`, `integration`, `serving-hazards`, `soak` and `conformance`;
   - `extension` (`extension/justfile`): `build`, `check`, `pie` and the benchmarks;
   - `load` (`tests/load/justfile`): `run`, `up` and `down`.
2. **One integration recipe, with the server as its argument**: `just test integration <server> [phpunit arguments]`, for `php-cli`, `frankenphp`, `frankenphp-worker`, `nginx-fpm`, `nginx-fpm-h2c` and `caddy-fpm`. It builds the server's image (none for `php-cli`), adds the worker suite for `frankenphp-worker`, and passes everything after the server to PHPUnit, so a group or a filter on any server is the same command: `just test integration <server> --group failure-nightly`. A CI matrix lists servers, never commands.
3. **What is not the server stays an environment variable**, which the harness reads anyway: the Restate image (`RESTATE_PHP_RESTATE_IMAGE`, to run against another Restate release) and the Restate mode (`RESTATE_PHP_TEST_RESTATE`). With `process` the recipe runs `tests/Integration` without `examples/testing`, which shows a user the default container. (The `external` mode and its devenv profiles were removed by [Test Matrix](0045-test-matrix.md).)
4. **`bin/` keeps the programs**, as in [0041](0041-just-recipes.md): `bench-compare.sh`, `conformance-local.sh`, `split.sh` and `check-packages.php`, which a recipe runs with its arguments.

### Rejected

- **Composer scripts as aliases of the recipes.** Two names for each task, and the aliases were what turned per-server and per-group variants into scripts of their own.
- **A recipe argument for the Restate image or mode.** Both apply to every recipe that starts Restate and to the harness outside any recipe; an argument of one recipe would only re-export the variable.

## Consequences

- There is one task list, `just --list` (with `just --list <module>`).
- Running a task needs just, which the dev shell provides ([0041](0041-just-recipes.md), decision 4); `composer run` no longer lists anything.
- Recipes call the tools from `vendor/bin/` themselves, since only Composer scripts had it on `PATH`.
- `just check` runs its steps as dependencies, in order, stopping at the first failure, as `composer check` did.
