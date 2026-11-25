#!/usr/bin/env bash
# Builds the extension (/app/extension) against the image's PHP, for the extension-bridge* stages of
# docker/Dockerfile, and stages the module under /out at the path an install puts it: restate.so in
# this PHP's extension_dir. The image stages copy /out/ to / and enable it with `extension=restate`.
#
#   EXTENSION_PROFILE   debug (the default: the debug assertions the suites run with) or release
#   EXTENSION_FEATURES  cargo features, comma-separated (diagnostics: the soak suite's builds)
#
# The Dockerfile mounts the Cargo registry and a target directory as caches shared by every stage.
# The target directory has a subdirectory per Debian release, PHP version and thread safety:
# ext-php-rs only generates its bindings again when PHP, PHP_CONFIG or PATH change, which are the
# same in every PHP image.
set -euo pipefail

profile=${EXTENSION_PROFILE:-debug}
case $profile in
    debug) cargo_profile=dev ;;
    release) cargo_profile=release ;;
    *) echo "EXTENSION_PROFILE must be debug or release, got '$profile'" >&2 && exit 64 ;;
esac
features=()
if [ -n "${EXTENSION_FEATURES:-}" ]; then features=(--features "$EXTENSION_FEATURES"); fi

# shellcheck source=/dev/null
. /etc/os-release
CARGO_TARGET_DIR=/cache/target/$VERSION_CODENAME-$(php -r 'echo PHP_VERSION, PHP_ZTS ? "-zts" : "-nts";')
export CARGO_TARGET_DIR

php-config --version
(cd /app/extension && cargo build -p restate --profile "$cargo_profile" "${features[@]}")

extension_dir=$(php -r 'echo ini_get("extension_dir");')
mkdir -p "/out$extension_dir"
cp "$CARGO_TARGET_DIR/$profile/librestate.so" "/out$extension_dir/restate.so"
# The module loads into this PHP, and so into the image stage on the same base.
php -d "extension=/out$extension_dir/restate.so" --ri restate >/dev/null
