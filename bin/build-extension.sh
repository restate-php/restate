#!/usr/bin/env bash
# Build the Restate PHP extension (module `restate`) and print the path of the built module
# on stdout. The module file is restate.so, the name `extension=restate` loads from PHP's
# extension_dir (copied from Cargo's librestate.so, or .dylib on macOS).
#
# Usage: bin/build-extension.sh [--release] [--php-config <path>] [-- <cargo build arguments>...]
#
#   (no options)          debug build against the PHP that PHP_CONFIG / php-config on PATH
#                         describe: extension/target/debug/restate.so
#   --release             release build (the workspace [profile.release]):
#                         extension/target/release/restate.so
#   --php-config <path>   build against the PHP that php-config describes, in a target
#                         directory of its own named after its PHP minor version and thread
#                         safety, e.g. extension/target/php8.4-zts/release/restate.so.
#                         Builds for different PHPs (NTS and ZTS, or two minors) live side by
#                         side and never overwrite each other or the default build.
#
# A module only loads into the PHP it was built against: same minor version, same thread
# safety (NTS or ZTS). The build needs libclang (bindgen); in the dev shell everything is set.
# The prebuilt binaries of a release (PIE's archives) are built with Nix instead:
# `composer build:pie` (bin/build-pie.sh, extension/flake.nix).
set -euo pipefail

usage() {
  sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
  exit 2
}

profile=debug
php_config=
cargo_args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --release) profile=release; shift ;;
    --php-config)
      [ $# -ge 2 ] || usage
      php_config=$2; shift 2 ;;
    --php-config=*) php_config=${1#*=}; shift ;;
    --) shift; cargo_args=("$@"); break ;;
    -h|--help) usage ;;
    *) echo "build-extension: unknown argument: $1" >&2; usage ;;
  esac
done

EXTENSION_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../extension" && pwd)
target_dir=$EXTENSION_DIR/target
# The PHP the module is built for: ext-php-rs runs $PHP (or php on PATH) unless --php-config
# names another.
php_binary=${PHP:-php}

if [ -n "$php_config" ]; then
  if ! php_config=$(command -v "$php_config") || [ ! -x "$php_config" ]; then
    echo "build-extension: php-config not found or not executable: $php_config" >&2
    exit 2
  fi
  php_config=$(cd "$(dirname "$php_config")" && pwd)/$(basename "$php_config")
  php_binary=$("$php_config" --php-binary)
  if [ ! -x "$php_binary" ]; then
    echo "build-extension: $php_config names $php_binary as its PHP binary, which does not exist" >&2
    exit 2
  fi
  flavor=$("$php_binary" -n -r 'echo "php", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, "-", PHP_ZTS ? "zts" : "nts";')
  # ext-php-rs reads the include paths from PHP_CONFIG and the thread safety and Zend API
  # version from `$PHP -i`: point both at the same PHP.
  export PHP_CONFIG=$php_config PHP=$php_binary
  target_dir=$EXTENSION_DIR/target/$flavor
  echo "build-extension: building for $flavor ($php_config) into $target_dir" >&2
elif ! command -v "${PHP_CONFIG:-php-config}" >/dev/null 2>&1; then
  echo "build-extension: php-config is required to build the ext-php-rs extension." >&2
  echo "Install the PHP development headers, enter the dev shell, or pass --php-config <path>." >&2
  exit 2
fi

release_flag=()
[ "$profile" = release ] && release_flag=(--release)

# Cargo reads extension/.cargo/config.toml and rustup extension/rust-toolchain.toml from the
# working directory, so build from there.
cd "$EXTENSION_DIR"
cargo build -p restate --target-dir "$target_dir" "${release_flag[@]}" "${cargo_args[@]}" >&2

built=$target_dir/$profile/librestate.so
if [ ! -f "$built" ]; then
  # macOS names the cdylib .dylib.
  built=$target_dir/$profile/librestate.dylib
fi
module=$target_dir/$profile/restate.so
# Copy, then rename over the old module: a PHP process that has the old one loaded keeps
# its mapping instead of seeing the file rewritten under it.
cp -f "$built" "$module.tmp.$$"
mv -f "$module.tmp.$$" "$module"
echo "$module"
