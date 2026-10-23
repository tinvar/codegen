<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Support\Str;

/**
 * Emits TypeScript for the Data objects a build uses, plus typed `@queries/*`
 * stubs (tinvar-spec.md, "Data boundary and TypeScript codegen").
 *
 * Fields come from DataShape, the same source the encoder walks, so the
 * generated interface always describes the payload that is actually produced.
 */
final class TypeScriptGenerator
{
    private TypeMapper $types;

    /** @var array<string, string> class => emitted interface body */
    private array $interfaces = [];

    public function __construct(private readonly string $header = '')
    {
        $this->types = new TypeMapper();
    }

    /**
     * @param list<class-string<Data>> $dataClasses
     * @param list<class-string>       $queryClasses
     */
    public function generate(array $dataClasses, array $queryClasses = []): string
    {
        $this->interfaces = [];
        $this->types = new TypeMapper();

        foreach ($dataClasses as $class) {
            $this->emitData($class);
        }

        $queries = array_map($this->describeQuery(...), $queryClasses);

        // Emitting a Data class can pull in others it references; keep going
        // until the set closes.
        while (($pending = array_diff_key($this->types->registered(), $this->interfaces)) !== []) {
            foreach (array_keys($pending) as $class) {
                $this->emitData($class);
            }
        }

        return $this->render($queries);
    }

    /** @param class-string $class */
    private function emitData(string $class): void
    {
        if (isset($this->interfaces[$class])) {
            return;
        }

        if (!is_subclass_of($class, Data::class)) {
            throw new CodegenException(
                "{$class} is not a Data object. Only Data objects cross to the renderer, so only they get types."
            );
        }

        $name = $this->types->register($class);
        $this->interfaces[$class] = '';   // claim the slot before recursing

        $reflection = new \ReflectionClass($class);
        $constructorDoc = $reflection->getConstructor()?->getDocComment() ?? false;
        $namespace = $reflection->getNamespaceName();
        $lines = [];

        foreach (DataShape::fieldsOf($class) as $property) {
            $field = $property->getName();
            $where = "{$class}::\${$field}";

            $type = $property->getType();
            if ($type === null) {
                throw new CodegenException("{$where} has no type; Data fields must be typed.");
            }

            $hint = DocBlock::typeFor($property->getDocComment(), $field)
                ?? DocBlock::typeFor($constructorDoc, $field);

            $lines[] = sprintf('  %s: %s;', $field, $this->types->map($type, $hint, $where, $namespace));
        }

        $this->interfaces[$class] = sprintf(
            "export interface %s {\n%s\n}\n",
            $name,
            implode("\n", $lines),
        );
    }

    /**
     * @param class-string $class
     * @return array{name: string, function: string, args: list<array{name: string, type: string}>, returns: string}
     */
    private function describeQuery(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(QueryAttribute::class)[0] ?? null;
        if ($attribute === null) {
            throw new CodegenException("{$class} has no #[Query] attribute.");
        }

        $declaration = $attribute->newInstance();
        $wireName = $declaration->name ?? Str::queryName($class);

        $args = [];
        foreach ($declaration->args as $argument => $modelClass) {
            // A model-typed argument crosses as its key, never as the model.
            $args[] = ['name' => $argument, 'type' => 'string'];
        }

        if ($args === [] && $reflection->hasMethod('__invoke')) {
            foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
                $type = $parameter->getType();
                $args[] = [
                    'name' => $parameter->getName(),
                    'type' => $type === null
                        ? 'unknown'
                        : $this->types->map($type, null, "{$class}::__invoke(\${$parameter->getName()})"),
                ];
            }
        }

        $returns = $this->returnTypeOf($reflection, $class);

        return [
            'name' => $wireName,
            'function' => self::functionName($wireName),
            'args' => $args,
            'returns' => $returns,
        ];
    }

    /** @param \ReflectionClass<object> $reflection */
    private function returnTypeOf(\ReflectionClass $reflection, string $class): string
    {
        if ($reflection->hasMethod('loadMany')) {
            // A batch query returns one result per call; a caller still awaits one.
            $doc = $reflection->getMethod('loadMany')->getDocComment();
            $element = DocBlock::elementType(DocBlock::returnType($doc) ?? '');
            if ($element === null) {
                throw new CodegenException(
                    "{$class}::loadMany() needs an `@return list<SomeData>` docblock so its call site can be typed."
                );
            }

            return $this->types->mapHint(
                $element['value'],
                "{$class}::loadMany()",
                $reflection->getNamespaceName(),
            );
        }

        $type = $reflection->getMethod('__invoke')->getReturnType();
        if ($type === null) {
            throw new CodegenException("{$class}::__invoke() needs a return type.");
        }

        return $this->types->map($type, null, "{$class}::__invoke()");
    }

    private static function functionName(string $wireName): string
    {
        $parts = explode('.', $wireName);
        $tail = array_pop($parts);

        return lcfirst($tail);
    }

    /** @param list<array{name: string, function: string, args: list<array{name: string, type: string}>, returns: string}> $queries */
    private function render(array $queries): string
    {
        $out = $this->header === '' ? '' : $this->header . "\n";
        $out .= "// Generated by tinvar codegen. Do not edit.\n";
        $out .= "// Source of truth: the PHP Data classes and #[Query] attributes.\n\n";

        foreach ($this->interfaces as $body) {
            $out .= $body . "\n";
        }

        if ($queries !== []) {
            $out .= "declare module '@queries/racks' {\n";
            foreach ($queries as $query) {
                $args = implode(
                    '; ',
                    array_map(static fn (array $a): string => "{$a['name']}: {$a['type']}", $query['args']),
                );
                $out .= sprintf(
                    "  /** Calls the PHP query `%s` over the request's socket. */\n  export function %s(args: { %s }): Promise<%s>;\n",
                    $query['name'],
                    $query['function'],
                    $args,
                    $query['returns'],
                );
            }
            $out .= "}\n";
        }

        return $out;
    }
}
