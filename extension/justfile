set lazy
set default-list
set ignore-comments

# Build the Restate PHP extension (module `restate`) and print the absolute path of the module,
[arg('php_config', long='php-config', help="php-config of the PHP to build for, into target/php<minor>-<nts|zts>/")]
[arg('profile', long='release', value='release', help="release build (the workspace's [profile.release])")]
[script('bash')]
build php_config='' profile='dev' *cargo_args:
    set -euo pipefail
    if [ -n {{ quote(php_config) }} ]; then
        # ext-php-rs reads the include paths from PHP_CONFIG and the thread safety and Zend API
        # version from `$PHP -i`: point both at the same PHP. ext-php-rs takes PHP_CONFIG only as
        # a path, so a name on PATH becomes its path (after the shell has run it once, so a
        # missing one fails with the shell's error). Each PHP gets a target directory of its own.
        PHP=$({{ quote(php_config) }} --php-binary)
        PHP_CONFIG=$(command -v {{ quote(php_config) }})
        CARGO_TARGET_DIR=target/$("$PHP" -n -r 'echo "php", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, "-", PHP_ZTS ? "zts" : "nts";')
        export PHP PHP_CONFIG CARGO_TARGET_DIR

        echo "build: building for $PHP_CONFIG into $PWD/$CARGO_TARGET_DIR" >&2
    fi

    built=$(cargo build -p restate --profile {{ profile }} --message-format=json-render-diagnostics {{ cargo_args }} | cargo artifacts list)
    module=$(dirname "$built")/restate.so

    # Rewriting the file in place would crash a PHP process that has it loaded. A rename leaves that
    # process the old file and new ones get the new module.
    cp -f "$built" "$module.tmp.$$"
    mv -f "$module.tmp.$$" "$module"

    echo "$module"

# Cross-compile the PIE archives of a release (the flake's packages.pie) and copy them into <dir>
[no-cd]
[script('bash')]
pie dir='dist':
    set -euo pipefail
    # The flake sees git-tracked files only: `git add` new files under extension/ first.
    result=$(nix build {{ quote(source_directory()) }}#pie --no-link --print-out-paths)

    mkdir -p {{ quote(dir) }}
    install -m 644 "$result"/* {{ quote(dir) }}/

    while read -r _ asset; do
        echo {{ quote(dir) }}/"$asset"
    done <"$result/SHA256SUMS"
