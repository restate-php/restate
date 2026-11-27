# cargo-artifacts: the paths of the files a `cargo build` produced, from its JSON messages
# (`just extension build`). Not in nixpkgs.
{
  lib,
  rustPlatform,
  fetchFromGitHub,
}:

rustPlatform.buildRustPackage (finalAttrs: {
  pname = "cargo-artifacts";
  version = "0.3.0";

  src = fetchFromGitHub {
    owner = "sagikazarmark";
    repo = "cargo-artifacts";
    tag = "v${finalAttrs.version}";
    hash = "sha256-FrcIhlVueEHbHXylf7b/bszpjru6efkHGUWACA2a/Os=";
  };

  cargoHash = "sha256-KFfQaH4jbfzsi5B6X32MV9CMsIChVBURQ37/uOQYOck=";

  meta = {
    description = "Copy artifacts from a cargo build message stream";
    homepage = "https://github.com/sagikazarmark/cargo-artifacts";
    license = lib.licenses.asl20;
    mainProgram = "cargo-artifacts";
  };
})
