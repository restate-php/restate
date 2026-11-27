set lazy
set default-list
set ignore-comments

# Build the PHP extension, its PIE archives, the Cargo workspace's checks and benchmarks
mod extension

# The PHPUnit suites: unit, integration (per server), serving hazards, soak, release, conformance (TESTING.md)
mod test 'tests'

# Load tests (TESTING.md, Load tests)
mod load 'tests/load'

# In order, stopping at the first failure.
[doc("Everything that must pass before a commit: cs, analyse, lint, the unit suite, the release test, the packages, then fmt/clippy/test for the Cargo workspace")]
check: cs analyse lint test::unit test::release check-packages extension::check

[doc("Check the coding style with php-cs-fixer (.php-cs-fixer.dist.php) and show the diff; changes nothing")]
cs:
    vendor/bin/php-cs-fixer fix --dry-run --diff

[doc("Fix the coding style with php-cs-fixer (.php-cs-fixer.dist.php)")]
cs-fix:
    vendor/bin/php-cs-fixer fix

[doc("PHPStan over the packages and the tests (phpstan.neon)")]
analyse:
    vendor/bin/phpstan analyse --memory-limit=512M

# The bash bodies of [script('bash')] recipes are not shellchecked, only the justfiles' formatting.
[doc("actionlint on .github/workflows, shellcheck on the shell scripts and just --fmt --check on the justfiles (all from the dev shell)")]
lint:
    actionlint
    shellcheck bin/*.sh docker/*/*.sh
    just --justfile justfile --fmt --check
    just --justfile extension/justfile --fmt --check
    just --justfile tests/justfile --fmt --check
    just --justfile tests/load/justfile --fmt --check

# composer validate --strict for each package in packages/, its code against its requirements
# (shipmonk/composer-dependency-analyser, with the extension loaded), the root composer.json
# against the packages, and that no package declares anything in the extension's
# Restate\Extension\ namespace (bin/check-packages.php).
[doc("Check the Composer packages in packages/ against their composer.json files and the root's (bin/check-packages.php)")]
check-packages: host-extension
    php -d extension=extension/target/debug/restate.so bin/check-packages.php

[doc("Build the extension, then serve test-services/index.php (every test service) on 0.0.0.0:9080 with it loaded")]
serve: host-extension
    php -d extension=extension/target/debug/restate.so -S 0.0.0.0:9080 -t test-services test-services/index.php

# OPcache on, JIT off (phpbench.json); e.g. just bench --filter=benchReplay.
[doc("Build the release extension, then run the PHP micro-benchmarks (tests/Benchmark); arguments go to phpbench")]
[positional-arguments]
bench *phpbench:
    {{ quote(just_executable()) }} --justfile {{ quote(justfile()) }} extension build --release >/dev/null
    vendor/bin/phpbench run --report=aggregate "$@"

[doc("Compare the PHP benchmarks of this checkout against a base revision (default: the merge base with main) in two reversed pairs plus callgrind instruction counts; arguments go to bin/bench-compare.sh")]
[positional-arguments]
bench-compare *args:
    bash bin/bench-compare.sh "$@"

# `list`, `split [<directory>...]` (the split commits of HEAD), or `publish [--version X.Y.Z]
# --remote <url with {repository}> [--dry-run]` (without a version: main only, as every push to
# main does), e.g. a dry run against https://github.com/restate-php/{repository}.git, or a
# rehearsal against local bare repositories.
[doc("Split the published directories into their repositories as a release does (bin/split.sh); arguments go to the script")]
[positional-arguments]
split *args:
    bash bin/split.sh "$@"

# The host's extension build (debug), which the PHP tools load.
[private]
host-extension:
    {{ quote(just_executable()) }} --justfile {{ quote(justfile()) }} extension build >/dev/null
