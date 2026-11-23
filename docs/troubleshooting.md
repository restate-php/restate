# Troubleshooting

First failures when installing the Restate PHP extension, starting an endpoint, registering it with Restate and serving invocations.

## Installing the extension

| Problem | Likely Cause | Fix | Read More |
| --- | --- | --- | --- |
| `BackendUnavailableException`: The Restate PHP extension (restate) is not loaded, and restate/sdk … requires Restate PHP extension contract version N | An endpoint was built (`Endpoint::serve()`, `Endpoint::psr()`, `Endpoint::builder()->build()`) in a PHP process that did not load the extension. There is no other backend. | Install it with PIE: `pie install restate/ext-restate`, which also enables it (`extension=restate`). Check with `php -m` under the same PHP and SAPI that serves (php-fpm and the CLI can read different php.ini files): it must list `restate`. Where PIE has no prebuilt binary, build it from source. | [Installing the extension](installation.md#installing-the-extension) |
| The loaded Restate PHP extension … implements contract version N, but … requires Restate PHP extension contract version M | The extension and `restate/sdk` come from different versions, whose interfaces between them differ. | Do what the message says: when the extension is older, install the version that matches the package (`pie install restate/ext-restate:<version>`); when it is newer, upgrade `restate/sdk` or install the extension that matches it. Restart the PHP server afterwards: a running server keeps the module it started with. | [Compatibility](compatibility.md), [Serving](serving.md#php-extension) |
| PIE finds no prebuilt archive for your platform | Prebuilt binaries cover Linux x86_64 and arm64 with glibc 2.28+ or musl, PHP 8.4, 8.5 and 8.6, NTS and ZTS, but not macOS, Windows or debug builds of PHP. | Build the extension from source. | [Building from source](installation.md#building-from-source) |
| PIE installs the extension but cannot enable it ("Expected extension restate to be loaded … but it was not detected"), or PHP reports ``version `GLIBC_2.xx' not found`` or a missing `libgcc_s.so.1` | The system's glibc is older than 2.28, or a minimal Alpine lacks `libgcc`, which the module needs. | On Alpine, `apk add libgcc`. With an older glibc, build from source on that system. | [Building from source](installation.md#building-from-source) |
| PHP refuses to load the extension (build ID or API mismatch) | The module was built for another PHP minor or thread-safety mode, for example an NTS build loaded into FrankenPHP's ZTS PHP. | Install it with PIE into the PHP that serves, which picks the matching build. From source, build against that PHP: `bash bin/build-extension.sh --release --php-config <that PHP's php-config>`. | [Serving](serving.md#php-extension) |
| A build from source fails with `'stdlib.h' file not found` | bindgen cannot find the C headers: in a checkout of this repository, the build ran outside the dev shell, which sets `BINDGEN_EXTRA_CLANG_ARGS`. | Run the build inside `devenv shell`, or set `BINDGEN_EXTRA_CLANG_ARGS` to your compiler's include flags. | [CONTRIBUTING.md](../CONTRIBUTING.md#building-the-extension) |

## Starting and registering

| Problem | Likely Cause | Fix | Read More |
| --- | --- | --- | --- |
| `php -S` exits with `Failed to listen on 0.0.0.0:9080 (reason: Address already in use)`, or Docker reports `port is already allocated` for 8080 or 9070 | Another process (often an earlier endpoint or Restate that is still running) holds the port. | Stop the other process (`ss -ltnp` or `lsof -i :9080` shows it), or pick another port. If you move the endpoint, register the new URL. | [Running with php -S](serving.md#running-with-php--s) |
| Registration fails with `META0003` "unable to reach the remote endpoint … Connection refused" | Restate cannot reach the URL you registered. `127.0.0.1` and `localhost` mean Restate's own host: with Restate in a container, that is the container. Or the endpoint listens on `127.0.0.1` only, which a container cannot reach. | Register an address Restate reaches: `http://host.docker.internal:9080` for Restate in Docker and PHP on the host, the service name in Docker Compose. Make PHP listen on `0.0.0.0`. Check that `/health` answers from where Restate runs. | [Registering the deployment](serving.md#registering-the-deployment) |
| With Restate in Docker on Linux, registration fails with `META0003` and a DNS error for `host.docker.internal` | Docker on Linux does not define `host.docker.internal`; Docker Desktop does. | Start the Restate container with `--add-host=host.docker.internal:host-gateway` (in Compose: `extra_hosts: ["host.docker.internal:host-gateway"]`). | [Registering the deployment](serving.md#registering-the-deployment) |
| Registration fails with `META0014` "server possibly supports only HTTP1.1" | Restate talks HTTP/2 to a server that speaks only HTTP/1.1, such as `php -S`. | Register with `"use_http_11": true` (CLI: `--use-http1.1`). | [Registering the deployment](serving.md#registering-the-deployment) |
| Registration fails with `META0003 got status code '401 Unauthorized'` | The endpoint has identity keys, and Restate does not sign its requests with the matching private key. | Give Restate the private key whose public key the endpoint trusts. Under PHP-FPM, check that `clear_env` does not hide the key's environment variable. | [Request Identity Validation](serving.md#request-identity-validation) |
| The ingress answers `404` "service '…' not found", or a handler you just added is missing | The endpoint is not registered, or it was registered before you added the service or handler. Registering a URL that is already registered changes nothing. | Register the endpoint. In development, register it again with `"force": true` (CLI: `--force`); in production, deploy the new version at a new URL. | [Deployments and versioning](concepts.md#deployments-and-versioning) |

## Serving invocations

| Problem | Likely Cause | Fix | Read More |
| --- | --- | --- | --- |
| Every attempt fails with `unexpected content type` (RT0012) | PHP printed a warning or notice before the endpoint ran (for example a request body over `post_max_size`), with `display_errors` on. | Set `display_errors=Off` with `log_errors=On`, and `enable_post_data_reading=0`. The endpoint's log names where the output started. | [PHP Settings](serving.md#php-settings) |
| Under PHP 8.6's `php -S`, attempts fail with `413` once the journal passes 8 MiB | The 8.6 built-in server checks `post_max_size` itself, even with `enable_post_data_reading=0`. | Add `-d post_max_size=0`. | [PHP Settings](serving.md#php-settings) |
| Behind PHP-FPM, attempts fail above some journal size (`411`, `413`, or `571 Protocol violation: The input was closed …`) | Restate sends request bodies without a length, and the front door's defaults do not buffer them (Caddy above 4 KiB, Apache above 16 KiB, nginx above 1 MiB). | Apply the front-door settings for your server. | [PHP-FPM and FastCGI](serving.md#php-fpm-and-fastcgi) |
| An attempt fails with code 573 and "… needs service protocol v7 (Restate 1.8 or newer) …" or "Feature … is not supported …" | A feature of Restate 1.8 (pausing, scopes and limit keys) used against Restate 1.7. | Upgrade Restate to 1.8 or newer. | [Restate 1.7](compatibility.md#restate-17) |
| Handler throws an exception and the invocation keeps retrying | Any exception other than `TerminalError` is reported as retryable, including a `\JsonException` from handler code or from encoding the output. Only undecodable handler input is a terminal `400`. | Throw `TerminalError` for expected business failures, or map exceptions with an error mapper. | [Error handling](develop/errors.md#terminal-and-retryable-errors) |

## Reporting a Bug

If the failure is not listed here, open an issue with:

- The PHP version, SAPI and thread safety: `php -v` (ZTS builds say so) and how you serve (`php -S`, PHP-FPM behind which front door, FrankenPHP classic or worker mode, a PSR-15 framework).
- The extension version: `php --ri restate`, or `Restate\Extension\version()` from the serving PHP, and the `restate/sdk` version (`composer show restate/sdk`).
- The Restate server version (`restate-server --version`, or the image tag).
- The operating system, CPU architecture and libc (glibc or musl).
- What you did and what happened: the command or the invocation, the full error, the endpoint's log (give the endpoint a [PSR-3 logger](develop/logging-and-hooks.md#logging)), and Restate's log around the failure.
