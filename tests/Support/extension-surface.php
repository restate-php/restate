<?php

declare(strict_types=1);

/*
 * Prints, as JSON, what PHP's reflection sees of the Restate extension's surface (every class and function in
 * `Restate\Extension`), so tests can compare two runtimes that cannot share a process:
 *
 *   php -d extension=…/restate.so extension-surface.php extension
 *   php extension-surface.php stub <path to extension/restate.stub.php>
 *
 * `optional` lists the stub functions marked `@restate-optional`, which only some builds define.
 */

const EXTENSION_NAMESPACE = 'Restate\\Extension\\';

function describeType(?ReflectionType $type): ?string
{
    return $type === null ? null : (string) $type;
}

/** @return list<array{name: string, type: ?string, optional: bool, variadic: bool, byReference: bool}> */
function describeParameters(ReflectionFunctionAbstract $function): array
{
    return array_map(static fn (ReflectionParameter $parameter): array => [
        'name' => $parameter->getName(),
        'type' => describeType($parameter->getType()),
        'optional' => $parameter->isOptional(),
        'variadic' => $parameter->isVariadic(),
        'byReference' => $parameter->isPassedByReference(),
    ], $function->getParameters());
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return array<string, mixed>
 */
function describeClass(ReflectionClass $class): array
{
    $own = static fn (ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member): bool => $member->getDeclaringClass()->getName() === $class->getName();

    $methods = [];
    foreach (array_filter($class->getMethods(), $own) as $method) {
        $methods[$method->getName()] = [
            'static' => $method->isStatic(),
            'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
            'final' => $method->isFinal(),
            'parameters' => describeParameters($method),
            'return' => describeType($method->getReturnType()),
        ];
    }
    ksort($methods);

    $properties = [];
    foreach (array_filter($class->getProperties(), $own) as $property) {
        $properties[$property->getName()] = [
            'static' => $property->isStatic(),
            'visibility' => $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private'),
            'type' => describeType($property->getType()),
            'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
        ];
    }
    ksort($properties);

    $constants = [];
    foreach (array_filter($class->getReflectionConstants(), $own) as $constant) {
        $constants[$constant->getName()] = $constant->getValue();
    }
    ksort($constants);

    $interfaces = $class->getInterfaceNames();
    sort($interfaces);

    return [
        'final' => $class->isFinal(),
        'abstract' => $class->isAbstract(),
        'interface' => $class->isInterface(),
        'parent' => $class->getParentClass() === false ? null : $class->getParentClass()->getName(),
        'interfaces' => $interfaces,
        'constants' => $constants,
        'properties' => $properties,
        'methods' => $methods,
    ];
}

$arguments = array_values(array_filter($_SERVER['argv'] ?? [], is_string(...)));
$mode = $arguments[1] ?? '';

if ($mode === 'extension') {
    $extension = new ReflectionExtension('restate');
    $classNames = $extension->getClassNames();
    $functionNames = array_keys($extension->getFunctions());
} elseif ($mode === 'stub' && isset($arguments[2])) {
    $classesBefore = get_declared_classes();
    $functionsBefore = get_defined_functions()['user'];
    require $arguments[2];
    $classNames = array_values(array_diff(get_declared_classes(), $classesBefore));
    $functionNames = array_values(array_diff(get_defined_functions()['user'], $functionsBefore));
} else {
    fwrite(STDERR, "usage: extension-surface.php extension | stub <file>\n");
    exit(2);
}

$surface = ['classes' => [], 'functions' => [], 'optional' => []];

foreach ($classNames as $name) {
    $class = new ReflectionClass($name);
    $surface['classes'][$class->getName()] = describeClass($class);
}

foreach ($functionNames as $name) {
    $function = new ReflectionFunction($name);
    $surface['functions'][$function->getName()] = [
        'parameters' => describeParameters($function),
        'return' => describeType($function->getReturnType()),
    ];
    // A stub function the extension defines only in some builds (a cargo feature) says so in its doc comment.
    if (str_contains((string) $function->getDocComment(), '@restate-optional')) {
        $surface['optional'][] = $function->getName();
    }
}

ksort($surface['classes']);
ksort($surface['functions']);
sort($surface['optional']);

$outside = array_filter(
    [...array_keys($surface['classes']), ...array_keys($surface['functions'])],
    static fn (string $name): bool => !str_starts_with($name, EXTENSION_NAMESPACE),
);
$surface['outsideNamespace'] = array_values($outside);

echo json_encode($surface, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
