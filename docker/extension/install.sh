#!/bin/sh
# Installs restate.so from the restate-extension context (mounted at /mnt/restate-extension) into
# the image's PHP, for the targets of docker/*/Dockerfile: into extension_dir, where
# `extension=restate` finds it, and checks that it loads into that PHP. A module loads only into
# the PHP minor and thread safety it was built for, so a mismatch between the base image and the
# module fails here.
set -eu

module=/mnt/restate-extension/restate.so
if [ ! -f "$module" ]; then
    echo "install-restate-extension: no restate.so in the restate-extension context: build the image with docker-bake.hcl (bin/build-image.sh), or pass --build-context restate-extension=<directory with restate.so>" >&2
    exit 1
fi

extension_dir=$(php -r 'echo ini_get("extension_dir");')
install -D -m 755 "$module" "$extension_dir/restate.so"

if ! php -n -d extension=restate --ri restate >/dev/null; then
    php -n -d extension=restate -r '' || true
    echo "install-restate-extension: restate.so does not load into PHP $(php -r 'echo PHP_VERSION, PHP_ZTS ? " ZTS" : " NTS";')" >&2
    exit 1
fi
