<?php

declare(strict_types=1);

namespace Restate\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Enforces the public API boundary (docs/decisions/0016-public-api-boundary.md).
 *
 * A class, interface or enum under the root namespace is internal when a namespace segment below the root is
 * `Internal` (`Restate\Internal\…`, `Restate\JsonSchema\Internal\…`) or it is in `Restate\Extension\`; everything else
 * is public. The rule reports:
 *
 * - an internal type whose PHPDoc lacks `@internal`;
 * - a public type that extends or implements an internal type;
 * - a public type whose public API — public members, plus protected members unless the type is final — mentions an
 *   internal type in a native or PHPDoc type: method parameters and returns, properties, constants;
 * - a public type, or a member of one, tagged `@internal` that is not listed in `$allowedInternal`. A listed member is
 *   exempt from the previous check; a listed type is still checked, because its subclasses expose its API. Listing
 *   them keeps every exception visible in one place.
 *
 * @implements Rule<InClassNode>
 */
final readonly class PublicApiBoundaryRule implements Rule
{
    /**
     * @param list<string> $allowedInternal names of public types (`Class`) and members (`Class::member`, without `$` or
     *                                    `()`) that may be tagged `@internal`
     * @param list<string> $excludedNamespaces namespace prefixes (with trailing `\`) this rule ignores, such as tests
     */
    public function __construct(
        private array $allowedInternal = [],
        private string $rootNamespace = 'Restate\\',
        private array $excludedNamespaces = ['Restate\\Tests\\'],
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $name = $class->getName();
        if ($class->isAnonymous() || !$this->isGoverned($name)) {
            return [];
        }

        if ($this->isInternalName($name)) {
            return $class->isInternal() ? [] : [
                RuleErrorBuilder::message(sprintf('%s is in an internal namespace, but its PHPDoc lacks @internal.', $name))
                    ->identifier('restate.publicApi.untaggedInternal')
                    ->build(),
            ];
        }

        $errors = [];
        if ($class->isInternal() && !in_array($name, $this->allowedInternal, true)) {
            $errors[] = RuleErrorBuilder::message(sprintf('%s is tagged @internal but lives in a public namespace and is not an allowed internal type.', $name))
                ->identifier('restate.publicApi.unlistedInternal')
                ->tip('Move it under an Internal namespace, or list it under allowedInternal in phpstan.neon and in docs/decisions/0016-public-api-boundary.md.')
                ->build();
        }

        $parent = $class->getParentClass();
        $supertypes = $parent === null ? [] : [$parent->getName()];
        foreach ($class->getImmediateInterfaces() as $interface) {
            $supertypes[] = $interface->getName();
        }
        foreach ($supertypes as $supertype) {
            if ($this->isInternalName($supertype)) {
                $errors[] = $this->leak($node, sprintf('%s extends or implements internal %s.', $name, $supertype));
            }
        }

        $native = $class->getNativeReflection();
        $exposesProtected = !$class->isFinal() && !$class->isEnum();

        foreach ($native->getMethods() as $nativeMethod) {
            if ($nativeMethod->getDeclaringClass()->getName() !== $name || $nativeMethod->isPrivate() || ($nativeMethod->isProtected() && !$exposesProtected)) {
                continue;
            }

            $member = $name . '::' . $nativeMethod->getName();
            $method = $class->getNativeMethod($nativeMethod->getName());
            if ($this->isExempt($node, $member, $method->isInternal()->yes(), $errors)) {
                continue;
            }

            foreach ($method->getVariants() as $variant) {
                foreach ($variant->getParameters() as $parameter) {
                    $this->check($node, $errors, sprintf('%s() parameter $%s', $member, $parameter->getName()), $parameter->getType(), $parameter->getNativeType());
                }
                $this->check($node, $errors, sprintf('%s() return type', $member), $variant->getReturnType(), $variant->getNativeReturnType());
            }
        }

        foreach ($native->getProperties() as $nativeProperty) {
            if ($nativeProperty->getDeclaringClass()->getName() !== $name || $nativeProperty->isPrivate() || ($nativeProperty->isProtected() && !$exposesProtected)) {
                continue;
            }

            $member = $name . '::' . $nativeProperty->getName();
            $property = $class->getNativeProperty($nativeProperty->getName());
            if ($this->isExempt($node, $member, $property->isInternal()->yes(), $errors)) {
                continue;
            }

            $this->check($node, $errors, sprintf('%s::$%s', $name, $nativeProperty->getName()), $property->getReadableType(), $property->getWritableType(), $property->getNativeType());
        }

        foreach ($native->getReflectionConstants() as $nativeConstant) {
            if ($nativeConstant->getDeclaringClass()->getName() !== $name || $nativeConstant->isPrivate() || ($nativeConstant->isProtected() && !$exposesProtected)) {
                continue;
            }

            $member = $name . '::' . $nativeConstant->getName();
            $constant = $class->getConstant($nativeConstant->getName());
            if ($this->isExempt($node, $member, $constant->isInternal()->yes(), $errors)) {
                continue;
            }

            $this->check($node, $errors, sprintf('constant %s', $member), $constant->getValueType(), $constant->getPhpDocType(), $constant->getNativeType());
        }

        return $errors;
    }

    private function isGoverned(string $name): bool
    {
        if (!str_starts_with($name, $this->rootNamespace)) {
            return false;
        }
        foreach ($this->excludedNamespaces as $excluded) {
            if (str_starts_with($name, $excluded)) {
                return false;
            }
        }

        return true;
    }

    private function isInternalName(string $name): bool
    {
        if (!$this->isGoverned($name)) {
            return false;
        }

        $segments = explode('\\', substr($name, strlen($this->rootNamespace)));
        array_pop($segments);

        return in_array('Internal', $segments, true) || ($segments[0] ?? null) === 'Extension';
    }

    /** @param list<IdentifierRuleError> $errors */
    private function isExempt(InClassNode $node, string $member, bool $taggedInternal, array &$errors): bool
    {
        if (!$taggedInternal) {
            return false;
        }

        if (!in_array($member, $this->allowedInternal, true)) {
            $errors[] = RuleErrorBuilder::message(sprintf('%s is tagged @internal in a public type but is not an allowed internal member.', $member))
                ->identifier('restate.publicApi.unlistedInternal')
                ->tip('Move it to an internal type, or list it under allowedInternal in phpstan.neon and in docs/decisions/0016-public-api-boundary.md.')
                ->line($node->getStartLine())
                ->build();
        }

        return true;
    }

    /** @param list<IdentifierRuleError> $errors */
    private function check(InClassNode $node, array &$errors, string $where, ?Type ...$types): void
    {
        $leaked = [];
        foreach ($types as $type) {
            foreach ($type?->getReferencedClasses() ?? [] as $referenced) {
                if ($this->isInternalName($referenced)) {
                    $leaked[$referenced] = true;
                }
            }
        }

        foreach (array_keys($leaked) as $referenced) {
            $errors[] = $this->leak($node, sprintf('%s exposes internal %s.', $where, $referenced));
        }
    }

    private function leak(InClassNode $node, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('restate.publicApi.leak')
            ->tip('Public API must not mention Internal types; see docs/decisions/0016-public-api-boundary.md.')
            ->line($node->getStartLine())
            ->build();
    }
}
