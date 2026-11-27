# Just Recipes

## Status

Accepted. Amends decision 2 of [Development Environment](0001-dev-environment.md), and decisions 5 and 6 of [Extension Flake](0037-extension-flake.md): tasks that take arguments or run in several steps are [just](https://just.systems) recipes, and the Composer scripts of the common tasks call them. Decisions 1 to 3 are superseded by [Just for Every Task](0043-just-for-every-task.md): every task is a just recipe, and `composer.json` has no scripts.

## Context

[0001](0001-dev-environment.md) made every task a Composer script, so there was one list of tasks, readable without Nix. The tasks that needed more than one command became shell scripts in `bin/`, which a Composer script called:
- the extension build (`bin/build-extension.sh`), the PIE archives (`bin/build-pie.sh`);
- the image-based suites (`bin/test-fpm.sh`, `bin/test-frankenphp.sh`, `bin/test-soak.sh`);
- the load tests (`bin/load-test.sh`) and the Rust core's benchmarks (`bin/bench-extension.sh`).

Each script parsed its own options, kept its usage text in a comment, and found the repository from its own path. Several of them were mostly that: a dispatcher over modes, or a docker build followed by phpunit. Composer passes arguments through to a script, but has no notion of options, defaults or several commands per task, and `composer run --list` shows one line per script.

## Decision

1. **Tasks with arguments or several steps are just recipes**, in a module of the root `justfile` next to what they work on:
   - `extension` (`extension/justfile`): `build`, `pie`, and the benchmarks `bench`, `bench-criterion` and `bench-compare`;
   - `test` (`tests/justfile`): `fpm`, `frankenphp`, `frankenphp-worker`, `serving-hazards` and `soak`, the suites that build an image first;
   - `load` (`tests/load/justfile`): `run`, `up` and `down` ([Load Tests in Compose](0040-load-tests-in-compose.md)).

   `just --list` (and `just --list <module>`) lists them, with their arguments. A recipe's settings stay environment variables when the PHPUnit suites read them too.
2. **Composer scripts stay the entry points of the common tasks**: `composer check`, `composer test`, `composer test:*`, `composer build:extension`, `composer bench*`, `composer load`. Several of them call a recipe. A recipe's logic is not repeated in a Composer script.
3. **`bin/` keeps the programs**: `bench-compare.sh`, `conformance-local.sh`, `split.sh` and `check-packages.php`. Each of them does more than dispatch, such as a base checkout in a worktree with its own `composer install`, or a release's split, so a recipe around it would only move it.
4. **The dev shell provides just and `cargo-artifacts`**, the tool `just extension build` reads the module's path from cargo's output with (`nix/packages/cargo-artifacts`, through `nix/overlay.nix`).
5. **The PIE archives are `nix build ./extension#pie`** (`just extension pie`), not `devenv build outputs.pie`. The extension flake declares restate-php.cachix.org in its `nixConfig`. The dev shell no longer pulls ext-php-rs.cachix.org, which had none of the paths the archives need that cache.nixos.org lacks.

### Rejected

- **Keeping the scripts.** Their option parsing, usage texts and path handling were what just provides itself.
- **devenv scripts or tasks.** They would tie the task list to Nix, which [0001](0001-dev-environment.md) avoided. just is one binary, and a justfile reads without it.
- **make.** No variadic arguments or options, and Makefiles are a poorer fit for tasks that are not files.

## Consequences

- There are two lists of tasks, `composer run --list` and `just --list`. The common tasks are in both.
- A contributor needs just, which the dev shell has.
- The bash bodies of `[script('bash')]` recipes are not shellchecked, as the scripts in `bin/` are (`composer check:scripts` checks the justfiles' formatting only).
- The load module uses just's unstable lists (`set unstable`, `set lists`), so a just upgrade can break it. No other module does.
- A recipe that runs from its module's directory writes its outputs relative to the repository (`source_directory()`), so running just from inside a module directory puts them in the same places.
