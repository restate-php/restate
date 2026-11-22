<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Restate\Tests\PHPStan\PublicApiBoundaryRule;

/** @extends RuleTestCase<PublicApiBoundaryRule> */
final class PublicApiBoundaryRuleTest extends RuleTestCase
{
    private const string TIP = 'Public API must not mention Internal types; see docs/decisions/0016-public-api-boundary.md.';

    protected function getRule(): Rule
    {
        return new PublicApiBoundaryRule(
            allowedInternal: ['BoundaryFixture\\Clean::create', 'BoundaryFixture\\AllowedBase'],
            rootNamespace: 'BoundaryFixture\\',
            excludedNamespaces: [],
        );
    }

    public function testReportsEveryLeakAndNothingElse(): void
    {
        $unlistedTip = 'Move it to an internal type, or list it under allowedInternal in phpstan.neon and in docs/decisions/0016-public-api-boundary.md.';

        $this->analyse([__DIR__ . '/../../PHPStan/Fixtures/public-api-boundary.php'], [
            ['BoundaryFixture\\Internal\\Untagged is in an internal namespace, but its PHPDoc lacks @internal.', 16],
            ['BoundaryFixture\\Leaky::$engine exposes internal BoundaryFixture\\Internal\\Engine.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::$helpers exposes internal BoundaryFixture\\Sub\\Internal\\Helper.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::__construct() parameter $engine exposes internal BoundaryFixture\\Internal\\Engine.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::engine() return type exposes internal BoundaryFixture\\Internal\\Engine.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::callback() return type exposes internal BoundaryFixture\\Sub\\Internal\\Helper.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::exposedBecauseNotFinal() parameter $port exposes internal BoundaryFixture\\Internal\\Port.', 63, self::TIP],
            ['BoundaryFixture\\Leaky::unlisted is tagged @internal in a public type but is not an allowed internal member.', 63, $unlistedTip],
            ['BoundaryFixture\\Extended extends or implements internal BoundaryFixture\\Internal\\Port.', 97, self::TIP],
            ['BoundaryFixture\\HiddenInPublicNamespace is tagged @internal but lives in a public namespace and is not an allowed internal type.', 102, 'Move it under an Internal namespace, or list it under allowedInternal in phpstan.neon and in docs/decisions/0016-public-api-boundary.md.'],
            ['BoundaryFixture\\AllowedBase::leak() return type exposes internal BoundaryFixture\\Internal\\Engine.', 107, self::TIP],
        ]);
    }
}
