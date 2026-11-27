# What a cargo build of the extension needs from the shell (`just extension build`; the Nix builds
# bring their own): PHP's headers, which ext-php-rs reads through php-config, and the libclang
# bindgen parses them with. Not in ext-php-rs.nix's devenv module, which declares Nix builds only.
{
  config,
  lib,
  pkgs,
  ...
}:

{
  # After the rest: the headers bring PHP's unwrapped `php` (no extensions), which must not shadow
  # languages.php's.
  packages = lib.mkAfter [
    config.languages.php.package.unwrapped.dev
    # Only the library bindgen loads (LIBCLANG_PATH): the `libclang` package's bin/ would put an
    # unwrapped clang ahead of the Nix cc wrapper, and the cc crate (CC=clang) would compile
    # ext-php-rs's C shim with it, failing on `stdlib.h`.
    pkgs.libclang.lib
  ];

  # Without it bindgen finds another libclang, which fails on the Nix compiler's headers.
  env.LIBCLANG_PATH = "${pkgs.libclang.lib}/lib";

  # libclang does not know the Nix libc and compiler include paths on its own; on Linux the build
  # then fails on `stdlib.h`. Reuse the flags the Nix cc wrapper would pass.
  enterShell = ''
    export BINDGEN_EXTRA_CLANG_ARGS="$(< "$NIX_CC/nix-support/libc-cflags") $(< "$NIX_CC/nix-support/cc-cflags") $NIX_CFLAGS_COMPILE"
  '';
}
