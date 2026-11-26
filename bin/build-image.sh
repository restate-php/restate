#!/usr/bin/env bash
# Builds one image of docker/Dockerfile through docker-bake.hcl, with the extension module for
# its PHP, and loads it into the container runtime under the given tag. The recipes and workflows
# that need an image run this.
#
# Usage: bin/build-image.sh [--php <minor>] [--variant <variant>] <target> <tag>
#
#   target     a docker/Dockerfile target: frankenphp-extension, fpm-extension,
#              fpm-extension-apache, upstream-frankenphp, upstream-fpm-nginx or
#              quickstart-php-restate
#   tag        the image's name
#   --php      the PHP minor, one of docker-bake.hcl's PHP_VERSIONS (default 8.4)
#   --variant  the module: debug (the default) or release, and for frankenphp-extension also
#              debug-diagnostics or release-diagnostics (the soak suite's)
#
# RESTATE_PHP_IMAGE_EXTENSION chooses where the module is built:
#
#   container  (the default) extension/Dockerfile, as a bake target: Nix in a container. Its first
#              build fetches about 1 GiB (the toolchain, the PHP headers, the crates) into a layer
#              that lasts until a lock file or the toolchain changes.
#   host       Nix on this machine (nix build ./extension#modules.…), handed to bake as
#              EXTENSION_DIR (var/image-extensions/). Where restate-php.cachix.org has the module
#              (any source CI built, on a Linux machine of the same CPU), Nix downloads only the
#              module. CI uses this.
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT_DIR"

usage() {
  echo "Usage: $0 [--php <minor>] [--variant <variant>] <target> <tag>" >&2
  exit 64
}

php=8.4
variant=debug
while [ $# -gt 0 ]; do
  case $1 in
    --php) [ $# -ge 2 ] || usage; php=$2; shift 2 ;;
    --variant) [ $# -ge 2 ] || usage; variant=$2; shift 2 ;;
    -*) usage ;;
    *) break ;;
  esac
done
[ $# -eq 2 ] || usage
target=$1-${php//./}-$variant
tag=$2

case ${RESTATE_PHP_IMAGE_EXTENSION:-container} in
  container) ;;
  host)
    # The extension target the image takes its module from, extension-<minor>-<ts>-<variant>.
    extension=$(docker buildx bake --print "$target" 2>/dev/null |
      jq -r --arg target "$target" '.target[$target].contexts["restate-extension"] // empty | sub("^target:"; "")')
    if [ -z "$extension" ]; then
      echo "build-image: docker-bake.hcl has no target $target" >&2
      exit 64
    fi
    IFS=- read -r _ minor ts module_variant <<<"$extension"
    # The CPU of the images the runtime builds and runs.
    case $(docker version --format '{{.Server.Arch}}') in
      amd64) arch=x86_64 ;;
      arm64) arch=aarch64 ;;
      *) echo "build-image: no extension module for $(docker version --format '{{.Server.Arch}}')" >&2 && exit 64 ;;
    esac
    out=$(nix build --no-link --print-out-paths "./extension#modules.php$minor.$ts.$arch-linux-glibc.$module_variant")
    export EXTENSION_DIR=var/image-extensions
    mkdir -p "$EXTENSION_DIR/$extension"
    install -m 644 "$out/lib/php/extensions/restate.so" "$EXTENSION_DIR/$extension/restate.so"
    ;;
  *) echo "build-image: RESTATE_PHP_IMAGE_EXTENSION is container or host, not '$RESTATE_PHP_IMAGE_EXTENSION'" >&2 && exit 64 ;;
esac

docker buildx bake --load --set "$target.tags=$tag" "$target"
