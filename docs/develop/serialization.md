# Serialization

Every value that crosses the wire or lands in the journal is bytes: handler input and output, state, `$ctx->run()`
results, call arguments, awakeable and promise values. This page covers how the SDK turns PHP values into those bytes
and back, how it describes payloads to Restate with JSON Schema, and how to plug in your own serializers.

## JSON by default

Without any configuration, payloads are JSON. Encoding uses `json_encode()` and decoding uses
`json_decode($bytes, true)`, both with `JSON_THROW_ON_ERROR`, so PHP's usual JSON rules apply: JSON objects decode to
associative arrays, objects encode through their public properties or `JsonSerializable`, and `1.0` is written as `1`.

A handler takes at most one payload parameter after its context, and its types decide how the SDK reads and writes it:

| Parameter or return type | Input | Output |
| --- | --- | --- |
| No payload parameter | Empty: the handler takes no input | – |
| `void` or `never` return type | – | Empty: no response body |
| `string`, `int`, `float`, `bool`, `null`, `true`, `false`, nullable forms and scalar unions | Checked against the type | Encoded as JSON |
| `array`, `mixed` or no type | The decoded JSON, unchecked | Encoded as JSON |
| A DTO class or backed enum | Mapped from JSON ([DTOs and enums](#dtos-and-enums)) | Mapped to JSON |
| `stdClass` | Not supported: JSON objects decode to arrays | Encoded as JSON |

Input that does not match its declared type fails the invocation with a terminal error with status 400, and so does
input that is not valid JSON. A handler that declares a payload parameter needs a JSON body: an empty body is invalid
JSON, even when the parameter is nullable or has a default. Send `null` instead. See
[Invalid input](errors.md#invalid-input).

Context operations use plain JSON too, without type mapping: a DTO you store with `$ctx->set()` is written through its
public properties and comes back from `$ctx->get()` as an associative array. Use a
[custom serializer](#custom-serializers) when you want the object back.

## DTOs and enums

Handler inputs and outputs typed as DTOs or backed enums are mapped from and to JSON at run time, and their JSON Schema
is inferred for [discovery](#json-schema-for-discovery). The built-in mapping needs no extra dependency and covers a
safe subset of PHP types:

| Supported | Details |
| --- | --- |
| Scalars | `string`, `int`, `float`, `bool`, `null`, the literal types `true` and `false`, their nullable forms, and unions of scalars and `null`. A nullable `true` or `false` literal is not inferred. |
| Backed enums | Also nullable; for a nullable enum payload the schema lists `null` in both `type` and `enum`. |
| Constructor DTOs | A public constructor whose parameters are all promoted public properties of supported types (other DTOs included), and no other public typed properties. |
| Property DTOs | No constructor, or a public one whose parameters all have defaults, and public, non-static, typed properties of supported types. |
| `stdClass` | Publishes a generic object schema, but decodes to an associative array, so a `stdClass` input needs a custom mapper or serializer. |

| Not supported | |
| --- | --- |
| Collections | Arrays or collections with item types, maps, generics |
| Type combinations | Intersections, unions of several classes, class unions mixed with non-null scalars |
| Classes | Recursive DTOs, unbacked enums, classes with a required constructor argument that is not a promoted property |

For those types, publish a schema yourself (`Payload::json($schema)`, `#[Input(schema: …)]`, `#[Output(schema: …)]`),
add a schema provider, and map the values with a [payload adapter](#symfony-and-valinor-adapters), a custom
`JsonValueMapper` or a [custom serializer](#custom-serializers). A handler whose type the built-in mapping cannot handle
fails with a terminal error with status 500: "DTO mapping is not available for Service/handler input." (or output).
Output that cannot be normalized is also a terminal 500.

Use `#[JsonName]` when a field's JSON name differs from its PHP name. Mapping and schema both use it:

```php
use Restate\JsonSchema\Attributes\JsonName;

final readonly class CreateUser
{
    public function __construct(
        #[JsonName('user_name')]
        public string $name,
        public ?Status $status = null,
    ) {
    }
}

enum Status: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
```

A handler that takes `CreateUser` accepts `{"user_name": "Ada", "status": "active"}`. Unknown fields are ignored, and a
missing required field is a terminal 400 that names it (`$.user_name: required field is missing`).

## JSON Schema for discovery

When Restate discovers your deployment, the SDK describes each handler's input and output, including a JSON Schema for
JSON payloads. Restate uses these schemas to build the OpenAPI documentation of your services. The SDK infers them from
handler types ([DTOs and enums](#dtos-and-enums)); when it cannot, it publishes an unknown schema (`true`, any value).

To publish a schema yourself, pass an array, a boolean or a schema built with `Restate\JsonSchema` to
`Payload::json()`, or an array or boolean to `#[Input(schema: …)]` and `#[Output(schema: …)]`:

```php
use Restate\Context;
use Restate\JsonSchema;
use Restate\Payload;
use Restate\Service;

$person = JsonSchema::object(
    JsonSchema::string()->title('Display name')->property('displayName')->required(),
    JsonSchema::integer()->title('Age')->property('age'),
)->denyAdditionalProperties();

$people = Service::new('People')->handler(
    'register',
    static fn (Context $ctx, array $person): string => $person['displayName'],
    Payload::json($person, required: true),
);

$person->toArray();
// ['type' => 'object', 'properties' => ['displayName' => ['title' => 'Display name', 'type' => 'string'],
//  'age' => ['title' => 'Age', 'type' => 'integer']], 'required' => ['displayName'], 'additionalProperties' => false]
```

`property('displayName')` names the property in the JSON object; `title()` is only an annotation. `required()` adds the
property to the `required` list of the object that collects it.

The builders produce JSON Schema Draft 2020-12, the dialect of the OpenAPI 3.1 document Restate generates. A builder
value is a subschema: it has no `$schema` key, so you can nest it anywhere. `JsonSchema::document($person)` wraps it as a
standalone document, which adds the Draft 2020-12 `$schema` URI at an object root (replacing any `$schema` already
there). A boolean schema document stays the literal `true` or `false`.

Boolean schemas are not the same as schemas for boolean values:

```php
JsonSchema::boolean()->toArray();     // ['type' => 'boolean']
JsonSchema::alwaysValid()->toArray(); // true
JsonSchema::neverValid()->toArray();  // false
```

Use `JsonSchema::raw([...])` for externally generated schemas or vendor keywords such as `x-vendor-keyword`. It rejects
a root `$schema` other than Draft 2020-12. To publish a complete document in another dialect, for example Draft-07 from
a generator, pass the array to `Payload::json([...])` or `JsonSchemaMetadata::fromArray()`: both publish it unchanged,
and Restate stores it without interpreting it.

`JsonSchema::schemaFor(Order::class)` generates a schema from a DTO class. Mark a class, property or constructor
parameter with `#[RawSchema]` when native PHP types cannot express its schema, such as array item types or map value
types; the generator uses the fragment as given. A class can also implement
`Restate\JsonSchema\Generation\ProvidesSchema` to build its own schema, or `ProvidesExamples` to add `examples`.

```php
use Restate\JsonSchema;
use Restate\JsonSchema\Attributes\RawSchema;
use Restate\Payload;

final readonly class Order
{
    public function __construct(
        #[RawSchema(['type' => 'array', 'items' => ['type' => 'string']])]
        public array $lineItemIds,
        #[RawSchema(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])]
        public array $quantitiesBySku,
    ) {
    }
}

$orderInput = Payload::json(JsonSchema::schemaFor(Order::class), required: true);
```

Inference for discovery does not read `#[RawSchema]`, and the built-in mapping does not support `array` fields, so a
handler taking `Order` needs both the explicit schema above and a [payload adapter](#symfony-and-valinor-adapters).

Schema providers (`Restate\JsonSchemaProvider`) supply schemas for handlers without an explicit one. The endpoint's
default options use `BuiltinJsonSchemaProvider`, the inference described above. To change them, pass
`JsonPayloadOptions` to `Endpoint::builder()->jsonPayloads()`. Options you build with `JsonPayloadOptions::new()` use
only the providers you add: `withSchemaProviders($first, ...$fallbacks)` tries each in order (via
`FallbackJsonSchemaProvider`) until one does not throw `UnsupportedJsonSchemaException`.

## Content types

JSON payloads use `application/json`. Payload metadata can keep the serializer's content type separate from the content
types Restate accepts for input, and can make Restate set the output content type even for an empty response:

```php
use Restate\Attributes as Restate;
use Restate\Context;
use Restate\Payload;
use Restate\Service;

#[Restate\Service('Greeter')]
final class Greeter
{
    #[Restate\Handler('greet')]
    #[Restate\Input(accept: 'application/*+json, application/json')]
    #[Restate\Output(setContentTypeIfEmpty: true)]
    public function greet(Context $ctx, string $name): string
    {
        return "Hello {$name}";
    }
}

$greeter = Service::new('Greeter')->handler(
    'greet',
    static fn (Context $ctx, string $name): string => "Hello {$name}",
    Payload::json()->withAcceptContentType('application/*+json, application/json'),
    Payload::json()->withSetContentTypeIfEmpty(),
);
```

Each `Payload` factory has an attribute equivalent:

| Builder | Attribute | Payload |
| --- | --- | --- |
| `Payload::json($schema, required: …)` | `#[Input(schema: …, required: …)]`, `#[Output(schema: …)]` | JSON, the default. `required: true` (input only) tells Restate the body is mandatory. |
| `Payload::binary('image/png')` | `#[Input(json: false, contentType: 'image/png')]` | Raw bytes: the handler gets a `string` and must return one. No schema. |
| `Payload::custom(MySerde::class, $schema)` | `#[Input(serde: MySerde::class)]` | A [custom serializer](#custom-serializers); its content type is the serializer's. |
| `Payload::empty()` | `#[Input(empty: true)]`, `#[Output(empty: true)]` | No body. |
| `->withAcceptContentType($patterns)` | `#[Input(accept: …)]` | Input only: content types Restate accepts. |
| `->withSetContentTypeIfEmpty()` | `#[Output(setContentTypeIfEmpty: true)]` | Output only. |

A schema on `Payload::custom()` is published only when the serializer's content type is `application/json`. The
attributes reject contradictory combinations, such as `serde` with `empty: true` or a JSON payload with a content type
other than `application/json`.

## Symfony and Valinor adapters

If your application already maps DTOs with Symfony Serializer or Valinor, use that library instead of the built-in
mapping. A payload adapter is not a serializer: the SDK still decodes and encodes the JSON bytes, and the adapter
converts the decoded arrays and scalars to and from your DTOs.

Adapters handle class types only. Scalars, `array`, backed enums and `stdClass` stay with the built-in mapping. Because
groups, serialized names, key converters and normalizers can change the wire shape independently of the PHP types, an
adapter publishes an unknown schema for its classes. Give an explicit schema (`#[Input(schema: …)]`,
`#[Output(schema: …)]` or `Payload::json($schema)`) when discovery needs an accurate one.

Do not use `#[Input(serde: …)]` or `#[Output(serde: …)]` for adapter-mapped DTOs: a serializer bypasses JSON mapping
entirely.

`withPayloadAdapter()` registers one adapter as both the value mapper and the schema provider. To keep inferred schemas
for scalars and enums, or to add your own schema provider, set the two separately and list the providers in order:

```php
use Restate\BuiltinJsonSchemaProvider;
use Restate\JsonPayloadOptions;

// $adapter is a SymfonyJsonPayloadAdapter or ValinorJsonPayloadAdapter (see below);
// MyJsonSchemaProvider is your own Restate\JsonSchemaProvider.
$jsonPayloads = JsonPayloadOptions::new()
    ->withValueMapper($adapter)
    ->withSchemaProviders(new MyJsonSchemaProvider(), $adapter, new BuiltinJsonSchemaProvider());
```

### Symfony Serializer

```bash
composer require symfony/serializer symfony/property-access symfony/property-info
```

The adapter denormalizes handler input, which is whatever the caller sent. Use Symfony Serializer 6.4.50+ or 7.4.23+;
earlier versions let a request read server files through `DataUriNormalizer` or uploaded-file denormalization, and reach
`#[Ignore]`d properties through dotted keys. The SDK is tested with 6.4 and 7.4; 8.x is not tested (if you use it, take
8.1.11 or newer).

```php
use Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter;
use Restate\Endpoint;
use Restate\JsonPayloadOptions;

// $symfonySerializer is a Symfony\Component\Serializer\Serializer with an ObjectNormalizer;
// UserService is a service class whose handlers take and return DTOs.
$adapter = new SymfonyJsonPayloadAdapter($symfonySerializer);

$endpoint = Endpoint::builder()
    ->jsonPayloads(JsonPayloadOptions::new()->withPayloadAdapter($adapter))
    ->bind(new UserService())
    ->build();
```

Symfony attributes such as `#[SerializedName]`, `#[Groups]`, `#[Ignore]` and `#[Context]` work through your normalizer.
The constructor also takes `normalizationContext`, `denormalizationContext` and a `contextResolver` callable that
receives a `JsonValueMappingContext` and returns extra context, for example serialization groups by role
(`JsonValueMappingContext::ROLE_INPUT` or `ROLE_OUTPUT`). See `examples/symfony-json-payloads.php`.

### Valinor

```bash
composer require cuyz/valinor
```

The SDK is tested with Valinor 2.

```php
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use Restate\Bridge\Valinor\ValinorJsonPayloadAdapter;
use Restate\Endpoint;
use Restate\JsonPayloadOptions;

// UserService is a service class whose handlers take and return DTOs.
$adapter = new ValinorJsonPayloadAdapter(
    new MapperBuilder()->mapper(),
    new NormalizerBuilder()->normalizer(Format::array()),
);

$endpoint = Endpoint::builder()
    ->jsonPayloads(JsonPayloadOptions::new()->withPayloadAdapter($adapter))
    ->bind(new UserService())
    ->build();
```

Configure `MapperBuilder` and `NormalizerBuilder` for your wire shape: key converters, value converters, custom
constructors, normalizer transformers. See `examples/valinor-json-payloads.php`.

## Custom serializers

A custom serializer implements `Restate\Serde` and owns the bytes completely, so JSON mapping and schema providers do not
run for it:

```php
use Restate\Serde;

final class ProfileSerde implements Serde
{
    public function serialize(mixed $value): string
    {
        if (!$value instanceof Profile) {
            throw new \InvalidArgumentException('Expected a Profile.');
        }

        return json_encode(['id' => $value->id, 'name' => $value->name], JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $bytes): Profile
    {
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        return new Profile($data['id'], $data['name']);
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}

final readonly class Profile
{
    public function __construct(public string $id, public string $name)
    {
    }
}
```

`contentType()` must not be empty. A `\JsonException` thrown while decoding handler input is a terminal 400. Wherever
the SDK accepts a serializer you can pass an instance or a class name; a class name needs a constructor without required
arguments.

For handler input and output, use `Payload::custom(ProfileSerde::class)` or `#[Input(serde: ProfileSerde::class)]` /
`#[Output(serde: ProfileSerde::class)]` (see [Content types](#content-types)).

For context operations, pass the serializer to the operation:

```php
use Restate\RunOptions;
use Restate\Target;

// Inside a Virtual Object handler; $repository loads profiles, $request is a ProfileRequest.
$ctx->set('profile', $profile, serde: ProfileSerde::class);
$profile = $ctx->get('profile', serde: ProfileSerde::class);

$result = $ctx->run(
    'load-profile',
    static fn (): Profile => $repository->load(),
    RunOptions::none()->withSerde(ProfileSerde::class),
);

$reply = $ctx->call(
    Target::service('Profiles', 'load'),
    $request,
    inputSerde: ProfileRequestSerde::class,
    outputSerde: ProfileSerde::class,
);
```

| Operation | Where the serializer goes |
| --- | --- |
| State | `$ctx->get($key, serde: …)`, `$ctx->set($key, $value, serde: …)` |
| Durable steps | `$ctx->run($name, $fn, serde: …)`, or `RunOptions::serde(…)` / `->withSerde(…)` |
| Calls and sends | `inputSerde:` and `outputSerde:` on `call()`, `callFuture()`, `send()`, `sendAfter()`; `CallOptions` / `SendOptions` (`::serde()`, `->withInputSerde()`, `->withOutputSerde()`); `->serde()`, `->inputSerde()`, `->outputSerde()` on runtime client requests |
| Invocation handles | `$ctx->invocation($id, serde: …)`, `$ctx->attach($target, serde: …)`, `->output(serde: …)` |
| Awakeables | `$ctx->awakeable(serde: …)`, `$ctx->resolveAwakeable($id, $value, serde: …)`, `->value(serde: …)` |
| Signals | `$ctx->signal($name, serde: …)`, `->resolve($value, serde: …)` on a signal handle |
| Durable promises | `$ctx->promise($name, serde: …)`, and `serde:` on `value()`, `peek()`, `future()`, `resolve()` |

See also [Custom serializers for state](state.md#custom-serializers-for-state) and, for callers outside Restate, the
ingress client's [Serialization](../ingress-client.md#serialization).

## Default serializers

Set a default serializer for a whole endpoint, or for one service built with `Service::new()`, `VirtualObject::new()`
or `Workflow::new()`. A service default overrides the endpoint default:

```php
use Restate\Endpoint;
use Restate\Service;

// MessagePackSerde and ProfileSerde implement Restate\Serde; $loadProfile is a handler closure.
$endpoint = Endpoint::builder()
    ->defaultSerde(MessagePackSerde::class)
    ->bind(
        Service::new('Profiles')
            ->defaultSerde(ProfileSerde::class)
            ->handler('load', $loadProfile),
    )
    ->build();
```

A default serializer applies to handler payloads without explicit metadata (other than empty ones) and to context
operations that do not pass their own. Explicit `Payload::json()`, `Payload::custom()`, `Payload::binary()`,
`Payload::empty()`, the `#[Input]` / `#[Output]` attributes and serializers passed to an operation always win. Like any
custom serializer, a default one bypasses JSON mapping and schema inference, so its payloads publish no schema.

## Journal value codecs

A journal value codec is a reversible byte transform, such as compression or encryption, that the endpoint applies to
every value after serialization and reverses before deserialization. It implements `Restate\JournalValueCodec`:

```php
use Restate\Endpoint;
use Restate\JournalValueCodec;

final class GzipJournalValueCodec implements JournalValueCodec
{
    public function encode(string $bytes): string
    {
        $encoded = gzencode($bytes);
        if ($encoded === false) {
            throw new \RuntimeException('Cannot compress value.');
        }

        return $encoded;
    }

    public function decode(string $bytes): string
    {
        // Values written before the codec was enabled are not gzip data: read them as they are.
        if (!str_starts_with($bytes, "\x1f\x8b")) {
            return $bytes;
        }

        $decoded = gzdecode($bytes);
        if ($decoded === false) {
            throw new \RuntimeException('Cannot decompress value.');
        }

        return $decoded;
    }
}

// Profiles is a service class defined with attributes.
$endpoint = Endpoint::builder()
    ->journalValueCodec(GzipJournalValueCodec::class)
    ->bind(new Profiles())
    ->build();
```

The codec wraps successful values: handler input and output, state, `$ctx->run()` results, call and send payloads and
results, invocation output, awakeables, signals and durable promises. It does not touch binary payloads
(`Payload::binary()`), terminal errors, content types, schemas, field names, headers, keys or discovery. The default is
`NoopJournalValueCodec`, which changes nothing.

Because handler input is decoded too, every caller must send encoded bytes and will receive encoded output. That
includes HTTP requests to Restate's ingress; give the [ingress client](../ingress-client.md#serialization) a serializer
that applies the same transform.

> [!WARNING]
> Restate stores the encoded bytes. Adding, removing or changing a codec after values have been journaled or stored
> breaks in-flight invocations and existing state, unless the new codec can still decode the old bytes, as the example
> above does for uncompressed values.

## Precedence

When several mechanisms could apply, the SDK picks the first that matches, in this order.

| Decision | Order |
| --- | --- |
| Handler payload format | 1. Explicit payload (`Payload::*`, `#[Input]`, `#[Output]`). 2. Service default serializer. 3. Endpoint default serializer. 4. JSON. A handler without input or with a `void` return stays empty. |
| Context operation format | 1. Serializer passed to the operation. 2. Service default serializer. 3. Endpoint default serializer. 4. JSON. |
| JSON value mapping | Skipped when a custom serializer is in use. Otherwise: 1. The endpoint's `JsonValueMapper`, if it supports the type. 2. The built-in mapping. 3. The decoded value as is, for `array`, `mixed` and untyped payloads. |
| Discovery schema | 1. Explicit schema on `Payload::json()` or the attribute. 2. The configured schema providers, in order (by default `BuiltinJsonSchemaProvider`; none for `JsonPayloadOptions::new()`). 3. Unknown (`true`). |
| Journal value codec | Always last when writing and first when reading, around whatever serializer produced the bytes. |

## Design notes

Background for contributors: [JSON payload mapping extension contract](../decisions/0003-json-payload-mapping-extension-contract.md),
[Symfony adapter](../decisions/0004-symfony-json-payload-adapter.md),
[Valinor adapter](../decisions/0005-valinor-json-payload-adapter.md),
[schema provider fallback](../decisions/0006-json-schema-provider-fallback.md),
[operation-level custom serialization](../decisions/0008-operation-level-custom-serialization.md),
[mapping-safe DTO schema inference](../decisions/0011-mapping-safe-dto-schema-inference.md),
[JSON Schema module](../decisions/0025-json-schema-module.md).
