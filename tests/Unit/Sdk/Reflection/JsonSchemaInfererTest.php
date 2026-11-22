<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Reflection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\Reflection\JsonSchemaInferer;
use stdClass;

final class JsonSchemaInfererFixture
{
    public function stringParam(string $value): void
    {
    }
    public function intParam(int $value): void
    {
    }
    public function floatParam(float $value): void
    {
    }
    public function boolParam(bool $value): void
    {
    }
    /** @param array<mixed> $value */
    public function arrayParam(array $value): void
    {
    }
    public function nullableStringParam(?string $value): void
    {
    }
    public function unionParam(string|int $value): void
    {
    }
    public function nullableUnionParam(string|int|null $value): void
    {
    }
    /** @param array<mixed>|string $value */
    public function arrayUnionParam(array|string $value): void
    {
    }
    public function unsupportedUnionParam(string|stdClass $value): void
    {
    }
    public function nullParam(null $value): void
    {
    }
    public function trueParam(true $value): void
    {
    }
    public function nullableTrueParam(?true $value): void
    {
    }
    public function falseParam(false $value): void
    {
    }
    public function nullableFalseParam(?false $value): void
    {
    }
    public function stdClassParam(stdClass $value): void
    {
    }
    /** @param iterable<mixed> $value */
    public function iterableParam(iterable $value): void
    {
    }
    public function objectParam(object $value): void
    {
    }
    public function callableParam(callable $value): void
    {
    }
    public function classParam(JsonSchemaInfererFixture $value): void
    {
    }
    public function intersectionParam(stdClass&\Stringable $value): void
    {
    }
    /** @param mixed $value */
    public function untypedParam($value): void
    {
    }

    public function stringReturn(): string
    {
        return 'ok';
    }
    public function voidReturn(): void
    {
    }
    public function nullReturn(): null
    {
        return null;
    }
    public function neverReturn(): never
    {
        throw new \RuntimeException('never returns');
    }
    /** @return mixed */
    public function untypedReturn()
    {
        return null;
    }
}

final class JsonSchemaInfererTest extends TestCase
{
    /**
     * @param array<string, mixed>|true $expected
     */
    #[DataProvider('parameterSchemaProvider')]
    public function testInfersSchemaFromParameterType(string $method, array|bool $expected): void
    {
        $reflection = new ReflectionMethod(JsonSchemaInfererFixture::class, $method);
        $type = $reflection->getParameters()[0]->getType();

        self::assertSame($expected, JsonSchemaInferer::schemaFor($type));
    }

    /** @return iterable<string, array{string, array<string, mixed>|true}> */
    public static function parameterSchemaProvider(): iterable
    {
        yield 'string' => ['stringParam', ['type' => 'string']];
        yield 'integer' => ['intParam', ['type' => 'integer']];
        yield 'number' => ['floatParam', ['type' => 'number']];
        yield 'boolean' => ['boolParam', ['type' => 'boolean']];
        yield 'array unknown' => ['arrayParam', true];
        yield 'nullable string' => ['nullableStringParam', ['type' => ['string', 'null']]];
        yield 'supported union' => ['unionParam', ['type' => ['string', 'integer']]];
        yield 'nullable supported union' => ['nullableUnionParam', ['type' => ['string', 'integer', 'null']]];
        yield 'array union unknown' => ['arrayUnionParam', true];
        yield 'unsupported union' => ['unsupportedUnionParam', true];
        yield 'null literal' => ['nullParam', ['type' => 'null']];
        yield 'true literal' => ['trueParam', ['type' => 'boolean', 'const' => true]];
        yield 'nullable true literal' => ['nullableTrueParam', ['anyOf' => [['type' => 'boolean', 'const' => true], ['type' => 'null']]]];
        yield 'false literal' => ['falseParam', ['type' => 'boolean', 'const' => false]];
        yield 'nullable false literal' => ['nullableFalseParam', ['anyOf' => [['type' => 'boolean', 'const' => false], ['type' => 'null']]]];
        yield 'stdClass object' => ['stdClassParam', ['type' => 'object']];
        yield 'iterable unknown' => ['iterableParam', true];
        yield 'object unknown' => ['objectParam', true];
        yield 'callable unknown' => ['callableParam', true];
        yield 'user class unknown' => ['classParam', true];
        yield 'intersection unknown' => ['intersectionParam', true];
        yield 'untyped unknown' => ['untypedParam', true];
    }

    #[DataProvider('emptyOutputProvider')]
    public function testDetectsEmptyOutputTypes(string $method, bool $expected): void
    {
        $reflection = new ReflectionMethod(JsonSchemaInfererFixture::class, $method);

        self::assertSame($expected, JsonSchemaInferer::isEmptyOutput($reflection->getReturnType()));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function emptyOutputProvider(): iterable
    {
        yield 'void' => ['voidReturn', true];
        yield 'null' => ['nullReturn', false];
        yield 'never' => ['neverReturn', true];
        yield 'string' => ['stringReturn', false];
        yield 'untyped' => ['untypedReturn', false];
    }
}
