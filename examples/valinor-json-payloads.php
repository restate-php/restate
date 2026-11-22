<?php

declare(strict_types=1);

use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\AsTransformer;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use Restate\Attributes as Restate;
use Restate\Bridge\Valinor\ValinorJsonPayloadAdapter;
use Restate\Context;
use Restate\Endpoint;
use Restate\JsonPayloadOptions;
use Restate\Payload;
use Restate\Service;

final class ValinorUserSchemas
{
    public const array CREATE_INPUT = [
        'type' => 'object',
        'required' => ['user_name'],
        'properties' => [
            'user_name' => ['type' => 'string'],
        ],
    ];

    public const array CREATE_OUTPUT = [
        'type' => 'object',
        'required' => ['user_id', 'user_name'],
        'properties' => [
            'user_id' => ['type' => 'string'],
            'user_name' => ['type' => 'string'],
        ],
    ];
}

final readonly class ValinorCreateUser
{
    public function __construct(
        public string $name,
    ) {
    }
}

final readonly class ValinorCreatedUser
{
    public function __construct(
        #[ValinorPrefixedWith('user_')]
        public string $id,
        #[ValinorPrefixedWith('user_')]
        public string $name,
    ) {
    }
}

#[AsTransformer]
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ValinorPrefixedWith
{
    public function __construct(private string $prefix)
    {
    }

    public function normalizeKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

#[Restate\Service('ValinorAttributeUsers')]
final class ValinorAttributeUserService
{
    #[Restate\Handler('create')]
    #[Restate\Input(schema: ValinorUserSchemas::CREATE_INPUT, required: true)]
    #[Restate\Output(schema: ValinorUserSchemas::CREATE_OUTPUT)]
    public function create(Context $ctx, ValinorCreateUser $input): ValinorCreatedUser
    {
        return new ValinorCreatedUser('user-1', $input->name);
    }
}

$mapper = new MapperBuilder()
    ->registerKeyConverter(static fn (string $key): string => str_starts_with($key, 'user_') ? substr($key, 5) : $key)
    ->mapper();

$normalizer = new NormalizerBuilder()->normalizer(Format::array());

$adapter = new ValinorJsonPayloadAdapter($mapper, $normalizer);

$jsonPayloads = JsonPayloadOptions::new()->withPayloadAdapter($adapter);

$builderService = Service::new('ValinorBuilderUsers')
    ->handler(
        'create',
        static fn (Context $ctx, ValinorCreateUser $input): ValinorCreatedUser => new ValinorCreatedUser('user-1', $input->name),
        Payload::json(ValinorUserSchemas::CREATE_INPUT, required: true),
        Payload::json(ValinorUserSchemas::CREATE_OUTPUT),
    );

Endpoint::builder()
    ->jsonPayloads($jsonPayloads)
    ->bind(new ValinorAttributeUserService())
    ->bind($builderService)
    ->build();

// Use Endpoint::serve([...]) or a PSR endpoint handler in a real application entrypoint.
