# The images of docker/frankenphp, docker/fpm and docker/quickstart (their Dockerfiles) for each
# PHP minor, each with the extension module built for its PHP (extension/Dockerfile: the
# extension flake's portable build, in a container). bin/build-image.sh builds one and is what the
# recipes and workflows run; by hand:
#
#   docker buildx bake --load frankenphp-extension-84-debug
#   docker buildx bake --print fpm-extension              # every fpm-extension image, as JSON
#   docker buildx bake extension-84-zts-debug --set '*.output=type=local,dest=dist/restate'
#
# A target is <its Dockerfile's target>-<PHP minor without the dot>-<variant>: the variant is
# debug or release, and for frankenphp-extension also debug-diagnostics or release-diagnostics (the
# soak suite's builds). An image takes its module from the extension target of its PHP minor, thread
# safety and variant (extension-<minor>-<nts|zts>-<variant>), so the module and the base image
# are always of the same PHP. With EXTENSION_DIR set, from $EXTENSION_DIR/<extension target>/
# instead: a directory with the module built outside Docker (bin/build-image.sh with
# RESTATE_PHP_IMAGE_EXTENSION=host).

# The PHP minors: each Dockerfile has a base stage of each (frankenphp-<minor>, php-fpm-<minor>,
# php-cli-<minor>), and the extension flake a module for each.
variable "PHP_VERSIONS" {
  type    = list(string)
  default = ["8.4", "8.5", "8.6"]
}

variable "EXTENSION_DIR" {
  default = ""
}

# The C library of the FrankenPHP and php-fpm images and of their module: glibc (Debian bases) or
# musl (their -alpine bases).
variable "LIBC" {
  default = "glibc"
}

# The base stage of a Dockerfile for a PHP minor and LIBC.
function "base" {
  params = [prefix, php]
  result = LIBC == "musl" ? "${prefix}-${php}-alpine" : "${prefix}-${php}"
}

function "minor" {
  params = [php]
  result = replace(php, ".", "")
}

# PHP 8.6's official cli and fpm images (php:8.6-cli-*, php:8.6-fpm-*) are ZTS; its NTS variant
# (php:8.6-nts-*) is CLI only, and the quickstart keeps php:8.6-cli, the image users get.
function "official_ts" {
  params = [php]
  result = php == "8.6" ? "zts" : "nts"
}

function "extension" {
  params = [php, ts, variant]
  result = "extension-${minor(php)}-${ts}-${variant}"
}

# The restate-extension context of an image: the extension target, or its directory under
# EXTENSION_DIR.
function "extension_context" {
  params = [php, ts, variant]
  result = EXTENSION_DIR != "" ? "${EXTENSION_DIR}/${extension(php, ts, variant)}" : "target:${extension(php, ts, variant)}"
}

group "default" {
  targets = ["frankenphp-extension-84-debug", "fpm-extension-84-debug"]
}

# An image with /restate.so only.
target "extension" {
  name = extension(php, ts, variant)
  matrix = {
    php     = PHP_VERSIONS
    ts      = ["nts", "zts"]
    variant = ["debug", "release", "debug-diagnostics", "release-diagnostics"]
  }
  context = "extension"
  args = {
    PHP_VERSION   = php
    THREAD_SAFETY = ts
    LIBC          = LIBC
    VARIANT       = variant
  }
}

target "frankenphp-extension" {
  name = "frankenphp-extension-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release", "debug-diagnostics", "release-diagnostics"]
  }
  dockerfile = "docker/frankenphp/Dockerfile"
  target     = "frankenphp-extension"
  contexts = {
    restate-extension = extension_context(php, "zts", variant)
  }
  args = {
    BASE_IMAGE = base("frankenphp", php)
  }
  tags = ["restate.local/restate-php-frankenphp:php${php}-${variant}"]
}

target "conformance-frankenphp" {
  name = "conformance-frankenphp-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/frankenphp/Dockerfile"
  target     = "conformance-frankenphp"
  contexts = {
    restate-extension = extension_context(php, "zts", variant)
  }
  args = {
    BASE_IMAGE = base("frankenphp", php)
  }
  tags = ["restate.local/restate-php-test-services:conformance-frankenphp-php${php}-${variant}"]
}

target "conformance-frankenphp-worker" {
  name = "conformance-frankenphp-worker-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/frankenphp/Dockerfile"
  target     = "conformance-frankenphp-worker"
  contexts = {
    restate-extension = extension_context(php, "zts", variant)
  }
  args = {
    BASE_IMAGE = base("frankenphp", php)
  }
  tags = ["restate.local/restate-php-test-services:conformance-frankenphp-worker-php${php}-${variant}"]
}

target "fpm-extension" {
  name = "fpm-extension-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/fpm/Dockerfile"
  target     = "fpm-extension"
  contexts = {
    restate-extension = extension_context(php, official_ts(php), variant)
  }
  args = {
    BASE_IMAGE = base("php-fpm", php)
  }
  tags = ["restate.local/restate-php-fpm:php${php}-${variant}"]
}

target "fpm-extension-apache" {
  name = "fpm-extension-apache-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/fpm/Dockerfile"
  target     = "fpm-extension-apache"
  contexts = {
    restate-extension = extension_context(php, official_ts(php), variant)
  }
  args = {
    BASE_IMAGE = base("php-fpm", php)
  }
  tags = ["restate.local/restate-php-fpm:apache-php${php}-${variant}"]
}

target "conformance-fpm-nginx" {
  name = "conformance-fpm-nginx-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/fpm/Dockerfile"
  target     = "conformance-fpm-nginx"
  contexts = {
    restate-extension = extension_context(php, official_ts(php), variant)
  }
  args = {
    BASE_IMAGE = base("php-fpm", php)
  }
  tags = ["restate.local/restate-php-test-services:conformance-fpm-nginx-php${php}-${variant}"]
}

target "conformance-fpm-caddy" {
  name = "conformance-fpm-caddy-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/fpm/Dockerfile"
  target     = "conformance-fpm-caddy"
  contexts = {
    restate-extension = extension_context(php, official_ts(php), variant)
  }
  args = {
    BASE_IMAGE = base("php-fpm", php)
  }
  tags = ["restate.local/restate-php-test-services:conformance-fpm-caddy-php${php}-${variant}"]
}

# Debian only: docker/quickstart/Dockerfile has no -alpine bases, so LIBC=musl does not apply.
target "quickstart-php-restate" {
  name = "quickstart-php-restate-${minor(php)}-${variant}"
  matrix = {
    php     = PHP_VERSIONS
    variant = ["debug", "release"]
  }
  dockerfile = "docker/quickstart/Dockerfile"
  target     = "quickstart-php-restate"
  contexts = {
    restate-extension = extension_context(php, official_ts(php), variant)
  }
  args = {
    BASE_IMAGE = "php-cli-${php}"
  }
  tags = ["restate-php-quickstart/php-restate:php${php}-${variant}"]
}
