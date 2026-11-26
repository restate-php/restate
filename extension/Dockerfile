# syntax=docker/dockerfile:1
# The module (restate.so) for one PHP minor and thread safety, for the CPU of the target platform:
# the flake's legacyPackages.modules, cross-compiled on the build platform (no emulation), in an
# image with /restate.so only. It loads into any PHP of that minor and thread safety on Linux with
# glibc 2.28 or newer, such as PHP's and FrankenPHP's official images.
#
#   docker buildx build extension --build-arg PHP_VERSION=8.4 --build-arg THREAD_SAFETY=zts \
#       --output type=local,dest=dist/restate
#
# PHP_VERSION     8.4, 8.5 or 8.6 (the flake's phps)
# THREAD_SAFETY   nts (the default) or zts
# VARIANT         debug (the default), release, debug-diagnostics or release-diagnostics (the
#                 soak suite's builds): the flake's variants
#
# It needs only this directory, which restate-php/ext-restate is split from. docker-bake.hcl at
# the repository's root builds it for the test images.

FROM --platform=$BUILDPLATFORM docker.io/nixos/nix:2.36.0 AS nix

# The image builds without Nix's sandbox already. Root is a trusted user, so the flake's
# nixConfig adds restate-php.cachix.org.
RUN cat >> /etc/nix/nix.conf <<'EOF'
experimental-features = nix-command flakes
accept-flake-config = true
max-jobs = auto
EOF

# The image's /bin/sh is bash (there is no /bin/bash).
SHELL ["/bin/sh", "-euo", "pipefail", "-c"]
WORKDIR /src

# What every module builds with but its source (the flake's moduleInputs: the toolchain, the PHP
# headers, the vendored crates), fetched from the files that decide it only. The flake evaluates
# with the crates' manifests; this layer lasts until a lock file, the toolchain or a manifest
# changes.
FROM nix AS inputs

COPY flake.nix flake.lock rust-toolchain.toml composer.json Cargo.toml Cargo.lock ./
COPY .cargo .cargo
COPY core/Cargo.toml core/
COPY extension/Cargo.toml extension/
RUN nix build --no-link --print-build-logs .#moduleInputs

FROM inputs AS build

# The module's source, as the flake's fileset has it: changes elsewhere (tests, docs) do not
# rebuild it.
COPY core core
COPY extension extension

ARG PHP_VERSION=8.4
ARG THREAD_SAFETY=nts
ARG VARIANT=debug
ARG TARGETARCH
RUN case $TARGETARCH in \
        amd64) arch=x86_64 ;; \
        arm64) arch=aarch64 ;; \
        *) echo "no module for $TARGETARCH" >&2 && exit 64 ;; \
    esac \
    && module=".#modules.php${PHP_VERSION/./}.$THREAD_SAFETY.$arch-linux-glibc.$VARIANT" \
    && echo "building $module" \
    && nix build --print-build-logs --out-link /result "$module" \
    && mkdir /out \
    && cp /result/lib/php/extensions/restate.so /out/restate.so

FROM scratch

COPY --from=build /out/ /
