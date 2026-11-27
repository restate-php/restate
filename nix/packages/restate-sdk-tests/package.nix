# The Restate SDK conformance suite: sdk-tests.jar of restatedev/e2e, at the release the official
# SDKs run, with the Java it is built for. `restate-sdk-tests run …` is `java -jar sdk-tests.jar
# run …` (bin/conformance-local.sh, TESTING.md). The jar is pinned by its hash: the release's tag
# has moved since it was cut, the jar has not.
{
  lib,
  stdenvNoCC,
  fetchurl,
  makeBinaryWrapper,
  jdk25_headless,
}:

stdenvNoCC.mkDerivation (finalAttrs: {
  pname = "restate-sdk-tests";
  version = "2.3";

  src = fetchurl {
    url = "https://github.com/restatedev/e2e/releases/download/v${finalAttrs.version}/sdk-tests.jar";
    hash = "sha256-9EUkd6X4ClmdoEF1p94cEZLZFltGtEWWTfgg6hsRf/o=";
  };

  dontUnpack = true;

  nativeBuildInputs = [ makeBinaryWrapper ];

  installPhase = ''
    runHook preInstall

    install -Dm644 "$src" "$out/share/restate-sdk-tests/sdk-tests.jar"
    # Java 25 warns on every run about what the jar's libraries do: native code through JNA
    # (Testcontainers) and sun.misc.Unsafe (Netty).
    makeWrapper ${jdk25_headless}/bin/java "$out/bin/restate-sdk-tests" \
      --add-flags "--enable-native-access=ALL-UNNAMED --sun-misc-unsafe-memory-access=allow -jar $out/share/restate-sdk-tests/sdk-tests.jar"

    runHook postInstall
  '';

  meta = {
    description = "The Restate SDK conformance suite (restatedev/e2e sdk-tests)";
    homepage = "https://github.com/restatedev/e2e";
    license = lib.licenses.mit;
    sourceProvenance = [ lib.sourceTypes.binaryBytecode ];
    mainProgram = "restate-sdk-tests";
    inherit (jdk25_headless.meta) platforms;
  };
})
