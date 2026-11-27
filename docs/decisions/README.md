# Decision Records

Why the SDK works the way it does: one record per decision, with its context, the options considered and the consequences. They are for contributors and for anyone curious about the design; to *use* the SDK, read the [documentation](../README.md) instead. Read the record of an area before changing it (see [CONTRIBUTING.md](../../CONTRIBUTING.md)).

## Architecture and the extension

| Record | |
| --- | --- |
| [0012](0012-single-native-backend.md) | Single Native Backend |
| [0013](0013-suspension-is-not-an-exception.md) | Suspension Is Not an Exception |
| [0014](0014-shared-core.md) | Shared Core |
| [0016](0016-public-api-boundary.md) | Public API Boundary |
| [0028](0028-request-identity-shared-core.md) | Request Identity Through sdk-shared-core |
| [0036](0036-tree-await-completed-handles.md) | Tree Awaits Report Their Completed Handles |
| [0007](0007-bidirectional-streaming.md) | Bidirectional Streaming |

## Features

| Record | |
| --- | --- |
| [0009](0009-call-send-option-headers.md) | Call And Send Option Headers |
| [0015](0015-exception-hierarchy.md) | Exception Hierarchy |
| [0017](0017-runtime-execution-hooks.md) | Runtime Execution Hooks |
| [0018](0018-handler-instantiation.md) | Handler Instantiation |
| [0019](0019-named-signals.md) | Named Signals |
| [0020](0020-selective-state-preloading.md) | Selective State Preloading |
| [0021](0021-pause.md) | Pause |
| [0022](0022-ingress-client.md) | Ingress Client |
| [0024](0024-scope-and-limit-key.md) | Scope and Limit Key |
| [0027](0027-sdk-logging.md) | SDK Logging |
| [0032](0032-stray-output.md) | Stray Output |

## Payloads, serialization and JSON Schema

| Record | |
| --- | --- |
| [0002](0002-json-schema-inference-dependency.md) | JSON Schema Inference Dependency |
| [0003](0003-json-payload-mapping-extension-contract.md) | JSON Payload Mapping Extension Contract |
| [0004](0004-symfony-json-payload-adapter.md) | Symfony JSON Payload Adapter |
| [0005](0005-valinor-json-payload-adapter.md) | Valinor JSON Payload Adapter |
| [0006](0006-json-schema-provider-fallback.md) | JSON Schema Provider Fallback |
| [0008](0008-operation-level-custom-serialization.md) | Operation-Level Custom Serialization |
| [0010](0010-endpoint-json-payload-configuration-ownership.md) | Endpoint JSON Payload Configuration Ownership |
| [0011](0011-mapping-safe-dto-schema-inference.md) | Mapping-Safe DTO Schema Inference |
| [0025](0025-json-schema-module.md) | JSON Schema Module |

## Serving and testing

| Record | |
| --- | --- |
| [0023](0023-test-harness.md) | Test Harness |
| [0033](0033-performance-benchmarks.md) | Performance Benchmarks |
| [0034](0034-php-fpm-serving.md) | PHP-FPM Serving |
| [0035](0035-worker-soak-testing.md) | Worker-Mode and Soak Testing |
| [0038](0038-native-restate-for-the-test-harness.md) | Native Restate for the Test Harness |
| [0039](0039-external-test-environments.md) | External Test Environments |
| [0040](0040-load-tests-in-compose.md) | Load Tests in Compose |

## Packaging, releases and development

| Record | |
| --- | --- |
| [0001](0001-dev-environment.md) | Development Environment |
| [0026](0026-composer-metadata.md) | Composer Metadata |
| [0029](0029-package-split.md) | Package Split |
| [0030](0030-prebuilt-extension-binaries.md) | Prebuilt Extension Binaries |
| [0031](0031-release.md) | Release |
| [0037](0037-extension-flake.md) | Extension Flake |
| [0041](0041-just-recipes.md) | Just Recipes |
| [0042](0042-image-builds-and-ci-caches.md) | Image Builds and CI Caches |
| [0043](0043-just-for-every-task.md) | Just for Every Task |
| [0044](0044-extension-modules-in-the-images.md) | Extension Modules in the Images |
| [0045](0045-test-matrix.md) | Test Matrix |
