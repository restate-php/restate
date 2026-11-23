<?php

declare(strict_types=1);

use Restate\Attributes as Restate;
use Restate\Bridge\Symfony\SymfonyJsonPayloadAdapter;
use Restate\Context;
use Restate\Endpoint;
use Restate\JsonPayloadOptions;
use Restate\JsonValueMappingContext;
use Restate\Payload;
use Restate\PayloadRole;
use Restate\Service;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Attribute\Context as SymfonyContext;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

require dirname(__DIR__) . '/vendor/autoload.php';

final class SymfonyUserSchemas
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
        'required' => ['user_id', 'user_name', 'created_at'],
        'properties' => [
            'user_id' => ['type' => 'string'],
            'user_name' => ['type' => 'string'],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
        ],
    ];
}

final class SymfonyCreateUser
{
    #[Groups(['user:write'])]
    #[SerializedName('user_name')]
    public string $name;

    #[Ignore]
    public ?string $debugNote = null;
}

final class SymfonyCreatedUser
{
    public function __construct(
        #[Groups(['user:read'])]
        #[SerializedName('user_id')]
        public readonly string $id,
        #[Groups(['user:read'])]
        #[SerializedName('user_name')]
        public readonly string $name,
        #[Groups(['user:read'])]
        #[SerializedName('created_at')]
        #[SymfonyContext([DateTimeNormalizer::FORMAT_KEY => DATE_ATOM])]
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}

#[Restate\Service('SymfonyAttributeUsers')]
final class SymfonyAttributeUserService
{
    #[Restate\Handler('create')]
    #[Restate\Input(schema: SymfonyUserSchemas::CREATE_INPUT, required: true)]
    #[Restate\Output(schema: SymfonyUserSchemas::CREATE_OUTPUT)]
    public function create(Context $ctx, SymfonyCreateUser $input): SymfonyCreatedUser
    {
        return new SymfonyCreatedUser('user-1', $input->name, new DateTimeImmutable());
    }
}

$metadataFactory = new ClassMetadataFactory(new AttributeLoader());
$nameConverter = new MetadataAwareNameConverter($metadataFactory);
$symfonySerializer = new Serializer([
    new DateTimeNormalizer(),
    new ObjectNormalizer($metadataFactory, $nameConverter, propertyTypeExtractor: new ReflectionExtractor()),
]);

$adapter = new SymfonyJsonPayloadAdapter(
    $symfonySerializer,
    contextResolver: static fn (JsonValueMappingContext $context): array => match ($context->role) {
        PayloadRole::Input => ['groups' => ['user:write']],
        PayloadRole::Output => ['groups' => ['user:read']],
    },
);

$jsonPayloads = JsonPayloadOptions::new()->withPayloadAdapter($adapter);

$builderService = Service::new('SymfonyBuilderUsers')
    ->handler(
        'create',
        static fn (Context $ctx, SymfonyCreateUser $input): SymfonyCreatedUser => new SymfonyCreatedUser('user-1', $input->name, new DateTimeImmutable()),
        Payload::json(SymfonyUserSchemas::CREATE_INPUT, required: true),
        Payload::json(SymfonyUserSchemas::CREATE_OUTPUT),
    );

Endpoint::builder()
    ->jsonPayloads($jsonPayloads)
    ->bind(new SymfonyAttributeUserService())
    ->bind($builderService)
    ->build();

// Use Endpoint::serve([...]) or a PSR endpoint handler in a real application entrypoint.
