# Handler Instantiation

## Status

Accepted. Related: [Runtime Execution Hooks](0017-runtime-execution-hooks.md).

## Context

An attributed service can be a pre-built object: `Endpoint::serve([new Greeter($repository)])` reflects the instance and binds each handler method to it. Under `php -S` or PHP-FPM that object lives for one request, but a long-lived worker (FrankenPHP worker mode, RoadRunner, Swoole) builds the endpoint once and serves many requests with the same object, so its dependencies (a database connection, an entity manager, a tenant-scoped client, per-request caches) leak from one invocation into the next. Frameworks solve that with containers that create or reset services per request, so the SDK needs a way to ask one.

The official SDKs do not have this problem in the same form: TypeScript and Python handlers are functions, and Java binds user-provided singletons (Spring injects the dependencies, still as singletons). PHP service classes with constructor injection are the common shape, so the SDK supports creating them per invocation.

## Decision

1. **A service class can be bound with a factory or a PSR-11 container** instead of an instance:

   ```php
   Endpoint::builder()
       ->bind(Greeter::class, static fn (Request $request): Greeter => new Greeter($pool->forTenant($request->header('x-tenant'))))
       ->container($container)   // PSR-11, for classes bound without a factory
       ->bind(Checkout::class)
       ->services([Payments::class, new Counter()])
       ->build();
   ```

   The lower-level form is `ServiceReflector::fromClass(Greeter::class, $factoryOrContainer): ServiceDefinition` (beside `fromObject()`). A factory gets the invocation's `Restate\Request` (target, headers, invocation id) and must return an instance of the class; a container is asked `get($class)`.
2. **When it is invoked: once per invocation attempt**, right before the handler runs — inside the handler interceptors (so a tracing span covers it, and its failure goes through the [Error Mapper](0017-runtime-execution-hooks.md#error-mapper)), after the context is built. In request/response mode every attempt is its own HTTP request, so "per attempt" is "per request"; there is no lifetime longer than an attempt that an instance could be scoped to without outliving the request. An exception from the factory or the container fails the attempt like a handler exception (retried unless mapped), and an object of another class fails it with an `\UnexpectedValueException`. Whether a container returns a new object per `get()` is the container's configuration (a non-shared service, or a container reset between requests); the SDK calls `get()` once per attempt and never caches the result.
3. **Discovery never instantiates.** The definition — handler names and kinds, payload metadata and schemas, the context-type check, documentation, metadata, retry and invocation configuration — is read from the class and its methods by reflection, when the endpoint is built. `HandlerDefinition` takes a `signature` (`\ReflectionFunctionAbstract`, a constructor parameter): the PHP signature payloads and the context type are read from, which is the callback's own unless the callback forwards to a method, as the per-invocation handlers do. The endpoint reads it back through the internal `HandlerDefinition::signature()`, and the internal `Reflection\Internal\HandlerPayloadInferer::payloadsFor()` accepts a signature as well as a callable.
4. **Errors are reported when the endpoint is built**, not on the first invocation: a class that does not exist or has no service attribute, a class bound without a factory when no container is configured, a container whose `has($class)` is false, and a factory given for an instance. `EndpointBuilder::build()` checks the services before loading the extension backend, so these errors do not depend on the extension.
5. **`psr/container` is required, not suggested** (`^1.1 || ^2.0`): `ContainerInterface` appears in public signatures (`EndpointBuilder::container()`, `ServiceReflector::fromClass()`), the package is a single interface file that every framework already installs, and a `suggest` would leave those signatures referring to a class that may not exist.

Closure-based services (`Service::new()->handler('greet', $closure)`) are unchanged: closures capture what they need, and a closure that wants per-invocation dependencies can resolve them itself.

## Consequences

- Public API: `ServiceReflector::fromClass()`, `EndpointBuilder::container()`, `EndpointBuilder::bind(object|string $service, ?callable $factory = null)`, `EndpointBuilder::services()` accepting class names, the `signature` constructor parameter of `HandlerDefinition`.
- `Endpoint::serve()`, `fromServices()` and `psr()` take instances (and builders); service classes need the builder, where a factory or container can be configured.
- Evidence: `tests/Unit/Sdk/HandlerFactoryTest.php` (definition and discovery without an instance, factory and container invoked per invocation with the request, a handler without input, type check of the factory result, instantiation inside the interceptors with its failure mapped, handler kinds and the context check from the methods, build-time errors) and `tests/Integration/HandlerFactoryTest.php` (a factory-built service on a real server: the tenant comes from the request, and the attempt that resumes after a `ctx.run` has its own instance).
