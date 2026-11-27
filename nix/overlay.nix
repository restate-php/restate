# The repository's own packages (nix/packages), for the dev shell (devenv.nix `overlays`).
final: _prev: {
  cargo-artifacts = final.callPackage ./packages/cargo-artifacts/package.nix { };
  restate-sdk-tests = final.callPackage ./packages/restate-sdk-tests/package.nix { };
}
