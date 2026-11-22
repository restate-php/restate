<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Context;
use Restate\Internal\Discovery;
use Restate\Internal\ServiceRegistry;
use Restate\InvocationConfiguration;
use Restate\ObjectContext;
use Restate\ObjectSharedContext;
use Restate\Service;
use Restate\ServiceDefinition;
use Restate\Tests\Support\FakeProtocolVersions;
use Restate\VirtualObject;
use Restate\Workflow;
use Restate\WorkflowContext;
use Restate\WorkflowSharedContext;

/**
 * Selective state preloading: `InvocationConfiguration::$statePreload` (true = all state eager, false = all lazy, a list
 * = only those keys eager) and its discovery manifest fields (`enableLazyState`, manifest v5 `eagerStateKeysWhitelist`).
 */
final class StatePreloadTest extends TestCase
{
    /** @return iterable<string, array{bool|list<string>, array<string, bool|list<string>>}> */
    public static function manifestFieldCases(): iterable
    {
        yield 'preload everything' => [true, ['enableLazyState' => false]];
        yield 'preload nothing' => [false, ['enableLazyState' => true]];
        yield 'preload some keys' => [['profile', 'cart'], ['enableLazyState' => true, 'eagerStateKeysWhitelist' => ['profile', 'cart']]];
        yield 'empty list preloads nothing' => [[], ['enableLazyState' => true]];
    }

    /**
     * @param bool|list<string> $statePreload
     * @param array<string, bool|list<string>> $expected
     */
    #[DataProvider('manifestFieldCases')]
    public function testStatePreloadMapsToTheManifestFields(bool|array $statePreload, array $expected): void
    {
        self::assertSame($expected, new InvocationConfiguration(statePreload: $statePreload)->manifestFields());
    }

    public function testUnsetStatePreloadLeavesTheServerDefault(): void
    {
        self::assertSame([], new InvocationConfiguration()->manifestFields());
        self::assertFalse(new InvocationConfiguration()->hasFields());
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidKeyLists(): iterable
    {
        yield 'not a list' => [['a' => 'profile'], 'State preload keys must be a list of strings.'];
        yield 'a non-string key' => [['profile', 7], 'State preload keys must be a list of strings.'];
    }

    /** @param array<array-key, mixed> $keys */
    #[DataProvider('invalidKeyLists')]
    public function testStatePreloadRejectsAnythingButAListOfStrings(array $keys, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        /** @phpstan-ignore argument.type (the invalid value is the point of the test) */
        new InvocationConfiguration(statePreload: $keys);
    }

    public function testManifestVersionFiveCarriesTheWhitelistForObjectsAndWorkflows(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Cart', invocationConfiguration: new InvocationConfiguration(statePreload: ['items']))
                ->handler('add', static fn (ObjectContext $ctx, string $item): string => $item)
                ->sharedHandler(
                    'view',
                    static fn (ObjectSharedContext $ctx): string => 'view',
                    invocationConfiguration: new InvocationConfiguration(statePreload: true),
                ),
            Workflow::new('Signup')
                ->run(
                    static fn (WorkflowContext $ctx, string $email): string => $email,
                    invocationConfiguration: new InvocationConfiguration(statePreload: ['email', 'step']),
                )
                ->sharedHandler(
                    'status',
                    static fn (WorkflowSharedContext $ctx): string => 'status',
                    invocationConfiguration: new InvocationConfiguration(statePreload: false),
                ),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 5), true, flags: JSON_THROW_ON_ERROR);
        [$cart, $signup] = $manifest['services'];

        self::assertTrue($cart['enableLazyState']);
        self::assertSame(['items'], $cart['eagerStateKeysWhitelist']);
        self::assertArrayNotHasKey('enableLazyState', $cart['handlers'][0]);
        self::assertFalse($cart['handlers'][1]['enableLazyState']);
        self::assertArrayNotHasKey('eagerStateKeysWhitelist', $cart['handlers'][1]);

        self::assertArrayNotHasKey('enableLazyState', $signup);
        self::assertTrue($signup['handlers'][0]['enableLazyState']);
        self::assertSame(['email', 'step'], $signup['handlers'][0]['eagerStateKeysWhitelist']);
        self::assertTrue($signup['handlers'][1]['enableLazyState']);
        self::assertArrayNotHasKey('eagerStateKeysWhitelist', $signup['handlers'][1]);
    }

    /** Restate 1.7 reads manifests up to v4, which has no whitelist: like the TypeScript and Python SDKs, discovery fails. */
    public function testManifestBeforeVersionFiveRejectsAServiceKeyList(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Cart', invocationConfiguration: new InvocationConfiguration(statePreload: ['items']))
                ->handler('add', static fn (ObjectContext $ctx): string => 'ok'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Cart preloads a list of state keys, which needs discovery manifest version 5 (Restate 1.8 or newer); this server negotiated version 4. Upgrade Restate, or preload all state (true) or none (false).');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 4);
    }

    public function testManifestBeforeVersionFiveRejectsAHandlerKeyList(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Cart')->handler(
                'add',
                static fn (ObjectContext $ctx): string => 'ok',
                invocationConfiguration: new InvocationConfiguration(statePreload: ['items']),
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler Cart/add preloads a list of state keys, which needs discovery manifest version 5');

        Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 4);
    }

    /** `true`, `false` and the empty list need only `enableLazyState` (manifest v3), so they work on Restate 1.7 too. */
    public function testManifestVersionFourAcceptsAllOrNothing(): void
    {
        $registry = new ServiceRegistry([
            VirtualObject::new('Eager', invocationConfiguration: new InvocationConfiguration(statePreload: true))
                ->handler('get', static fn (ObjectContext $ctx): string => 'ok'),
            VirtualObject::new('Lazy', invocationConfiguration: new InvocationConfiguration(statePreload: false))
                ->handler('get', static fn (ObjectContext $ctx): string => 'ok'),
            VirtualObject::new('Empty', invocationConfiguration: new InvocationConfiguration(statePreload: []))
                ->handler('get', static fn (ObjectContext $ctx): string => 'ok'),
        ]);

        $manifest = json_decode(Discovery::manifestJson($registry, FakeProtocolVersions::RANGE, 4), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([false, true, true], array_column($manifest['services'], 'enableLazyState'));
        self::assertSame([], array_column($manifest['services'], 'eagerStateKeysWhitelist'));
    }

    public function testPlainServiceRejectsStatePreload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter has no state: state preloading applies only to virtual objects and workflows.');

        Service::new('Greeter', invocationConfiguration: new InvocationConfiguration(statePreload: false))
            ->handler('greet', static fn (Context $ctx, string $name): string => $name)
            ->definition();
    }

    public function testPlainServiceHandlerRejectsStatePreload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter/greet has no state: state preloading applies only to virtual objects and workflows.');

        Service::new('Greeter')
            ->handler(
                'greet',
                static fn (Context $ctx, string $name): string => $name,
                invocationConfiguration: new InvocationConfiguration(statePreload: ['name']),
            )
            ->definition();
    }

    public function testServiceDefinitionRejectsStatePreloadOnAPlainService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service Greeter has no state');

        new ServiceDefinition('Greeter', [], ServiceDefinition::TYPE_SERVICE, invocationConfiguration: new InvocationConfiguration(statePreload: true));
    }
}
