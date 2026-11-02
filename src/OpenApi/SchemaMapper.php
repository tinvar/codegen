<?php

declare(strict_types=1);

namespace Tinvar\Codegen\OpenApi;

use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\DocBlock;
use Tinvar\Codegen\Imports;
use Tinvar\Data\CursorScroll;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\ProvidesData;
use Tinvar\Data\Scroll;
use Tinvar\Data\SharedRegistry;
use Tinvar\Support\Lazy;
use Tinvar\Support\Str;

/**
 * PHP types as JSON Schema (2020-12, which OpenAPI 3.1 speaks): what the
 * OpenAPI document says a value is (ADR 0159).
 *
 * The rules are codegen's own (TypeMapper), read the same way and refused
 * the same way, so the TypeScript a view is typed with and the schema a
 * script is shown never disagree:
 *
 *   - a Data class is a component, an object of every field it sends,
 *     each required -- a field that may be null is sent as null -- but an
 *     #[Optional] one, which is sent only when asked for;
 *   - a backed enum is a component of its values; a CarbonImmutable or
 *     any immutable moment, the ISO 8601 string it crosses as
 *     (`format: date-time`);
 *   - a type that allows null is that or null;
 *   - `list<T>` an array of T, `array<string, T>` an object of T, read from
 *     the docblock as codegen reads it, and refused without one;
 *   - `Lazy<T>` is T, which the encoder sends; a ProvidesData what it
 *     provides.
 *
 * A Scroll or a CursorScroll is a page's way of reading a list a page at a
 * time, and is refused: an API call answers with its value.
 */
final class SchemaMapper
{
    /** @var array<string, array<string, mixed>> component name => schema */
    private array $components = [];

    /** @var array<class-string, string> class => component name */
    private array $names = [];

    /** Namespace of the class being mapped, for short docblock names. */
    private ?string $namespace = null;

    /** @var array<string, string> what that class's file imports */
    private array $imports = [];

    /** @return array<string, array<string, mixed>> every component mapped, by name, sorted */
    public function components(): array
    {
        ksort($this->components);

        return $this->components;
    }

    /**
     * A declared type, its docblock's hint read in the file that declares it.
     *
     * @param \ReflectionClass<object>|null $declaring the class whose file names the hint's types
     * @return array<string, mixed>
     */
    public function type(\ReflectionType $type, ?string $hint, string $where, ?\ReflectionClass $declaring = null): array
    {
        return $this->within($declaring, fn (): array => $this->mapType($type, $hint, $where));
    }

    /**
     * A type written only in a docblock: `list<OrderData>`, `?string`.
     *
     * @param \ReflectionClass<object>|null $declaring
     * @return array<string, mixed>
     */
    public function hint(string $hint, string $where, ?\ReflectionClass $declaring = null): array
    {
        return $this->within($declaring, fn (): array => $this->mapHint($hint, $where));
    }

    /**
     * A reference to $class's component, mapping it first.
     *
     * @param class-string $class a Data class or a backed enum
     * @return array{'$ref': string}
     */
    public function reference(string $class, string $where): array
    {
        if (enum_exists($class)) {
            return $this->enum($class, $where);
        }
        if (!is_subclass_of($class, Data::class)) {
            throw new CodegenException("Cannot describe '{$class}' at {$where}: only Data objects, backed enums, scalars, lists and maps cross the boundary.");
        }

        return ['$ref' => '#/components/schemas/' . $this->data($class)];
    }

    /**
     * @param \ReflectionClass<object>|null $declaring
     * @template T
     * @param \Closure(): T $map
     * @return T
     */
    private function within(?\ReflectionClass $declaring, \Closure $map): mixed
    {
        $previous = [$this->namespace, $this->imports];
        if ($declaring !== null) {
            [$this->namespace, $this->imports] = [$declaring->getNamespaceName(), Imports::of($declaring)];
        }
        try {
            return $map();
        } finally {
            [$this->namespace, $this->imports] = $previous;
        }
    }

    /** @return array<string, mixed> */
    private function mapType(\ReflectionType $type, ?string $hint, string $where): array
    {
        if ($type instanceof \ReflectionUnionType) {
            $parts = array_map(fn (\ReflectionType $t): array => $this->mapType($t, $hint, $where), $type->getTypes());

            return self::anyOf(array_values($parts));
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw new CodegenException("Cannot describe an intersection type at {$where}.");
        }

        $name = $type->getName();
        $schema = match (true) {
            $name === 'mixed' => ['description' => 'Any value.'],
            $name === 'null' => ['type' => 'null'],
            $name === 'array' => $this->mapArray($hint, $where),
            $type->isBuiltin() => self::scalar($name) ?? throw new CodegenException("Cannot describe '{$name}' at {$where}."),
            default => $this->mapClass($name, $hint, $where),
        };

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? self::nullable($schema) : $schema;
    }

    /** @return array<string, mixed> */
    private function mapClass(string $class, ?string $hint, string $where): array
    {
        if ($class === Scroll::class || $class === CursorScroll::class) {
            throw new CodegenException("{$where} is a page's {$class}, which an API call does not answer with: answer with a list.");
        }
        if (is_a($class, Lazy::class, true)) {
            $element = DocBlock::lazyType($hint) ?? throw new CodegenException("No value type for the Lazy field at {$where}. Add `@var Lazy<list<UnitData>>`.");

            return $this->mapHint($element, $where);
        }
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (is_subclass_of($class, ProvidesData::class)) {
            try {
                [$classes, $nullable] = SharedRegistry::provided($class);
            } catch (\InvalidArgumentException $e) {
                throw new CodegenException("Cannot describe the ProvidesData at {$where}: {$e->getMessage()}", 0, $e);
            }
            $parts = array_map(fn (string $data): array => $this->reference($data, $where), $classes);

            return $nullable ? self::nullable(self::anyOf($parts)) : self::anyOf($parts);
        }
        if (enum_exists($class) && !is_subclass_of($class, \BackedEnum::class)) {
            throw new CodegenException("{$where} is a pure enum, which has no value to send; give {$class} a string or int backing.");
        }

        /** @var class-string $class */
        return $this->reference($class, $where);
    }

    /** @return array<string, mixed> */
    private function mapArray(?string $hint, string $where): array
    {
        $union = $hint === null ? [] : DocBlock::union(DocBlock::withoutNull($hint));
        if (\count($union) > 1) {
            return self::anyOf(array_map(fn (string $part): array => $this->mapHint($part, $where), $union));
        }
        if (DocBlock::elementType($hint) === null) {
            throw new CodegenException("No element type for the array at {$where}. Add a docblock such as `@var list<UnitData>`.");
        }

        return $this->mapHint(DocBlock::withoutNull((string) $hint), $where);
    }

    /** @return array<string, mixed> */
    private function mapHint(string $hint, string $where): array
    {
        $hint = trim($hint);
        if (str_starts_with($hint, '?')) {
            return self::nullable($this->mapHint(substr($hint, 1), $where));
        }
        $members = DocBlock::union($hint);
        if (\count($members) > 1) {
            $null = \in_array('null', $members, true);
            $parts = array_map(fn (string $m): array => $this->mapHint($m, $where), array_values(array_filter($members, static fn (string $m): bool => $m !== 'null')));
            $schema = self::anyOf($parts);

            return $null ? self::nullable($schema) : $schema;
        }
        if (str_starts_with($hint, '(') && str_ends_with($hint, ')')) {
            return $this->mapHint(substr($hint, 1, -1), $where);
        }
        if (DocBlock::scrollType($hint) !== null || DocBlock::cursorScrollType($hint) !== null) {
            throw new CodegenException("{$where} holds a page's Scroll, which an API call does not answer with: answer with a list.");
        }
        // `Lazy<T>` written whole, short or in full: the encoder sends T.
        if (preg_match('/^(?:\\\\?Tinvar\\\\Support\\\\)?Lazy<(.+)>$/s', $hint, $m) === 1) {
            return $this->mapHint($m[1], $where);
        }
        $element = DocBlock::elementType($hint);
        if ($element !== null) {
            $items = $this->mapHint($element['value'], $where);

            return $element['key'] !== null && $element['key'] !== 'number'
                ? ['type' => 'object', 'additionalProperties' => $items]
                : ['type' => 'array', 'items' => $items];
        }

        $scalar = self::scalar($hint);
        if ($scalar !== null) {
            return $scalar;
        }

        return match ($hint) {
            'mixed' => ['description' => 'Any value.'],
            'null' => ['type' => 'null'],
            'number' => ['type' => 'number'],
            default => $this->mapClass($this->resolve($hint, $where), null, $where),
        };
    }

    /** @return class-string */
    private function resolve(string $name, string $where): string
    {
        $candidates = [];
        if (str_starts_with($name, '\\')) {
            $candidates[] = substr($name, 1);
        } else {
            $segments = explode('\\', $name, 2);
            $imported = $this->imports[strtolower($segments[0])] ?? null;
            if ($imported !== null) {
                $candidates[] = isset($segments[1]) ? "{$imported}\\{$segments[1]}" : $imported;
            }
            if ($this->namespace !== null) {
                $candidates[] = "{$this->namespace}\\{$name}";
            }
            $candidates[] = $name;
        }
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) || enum_exists($candidate)) {
                /** @var class-string $candidate */
                return $candidate;
            }
        }

        throw new CodegenException("Cannot describe '{$name}' at {$where}: no class of that name is in reach of the file that names it.");
    }

    /**
     * A Data class's component, mapped once: claimed before its fields, so
     * one that holds itself refers to itself.
     *
     * @param class-string $class
     */
    private function data(string $class): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }
        $name = $this->claim($class);

        $reflection = new \ReflectionClass($class);
        $constructorDoc = $reflection->getConstructor()?->getDocComment() ?? false;
        $optional = DataShape::optional($class);
        $properties = [];
        $required = [];
        foreach (DataShape::fieldsOf($class) as $property) {
            $field = $property->getName();
            $where = "{$class}::\${$field}";
            $type = $property->getType() ?? throw new CodegenException("{$where} has no type; Data fields must be typed.");
            $hint = DocBlock::typeFor($property->getDocComment(), $field) ?? DocBlock::typeFor($constructorDoc, $field);
            $properties[$field] = $this->type($type, $hint, $where, $property->getDeclaringClass());
            if (!isset($optional[$field])) {
                $required[] = $field;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $this->components[$name] = $schema;

        return $name;
    }

    /**
     * A backed enum's component: the union of its values.
     *
     * @param class-string $enum
     * @return array{'$ref': string}
     */
    private function enum(string $enum, string $where): array
    {
        if (!is_subclass_of($enum, \BackedEnum::class)) {
            throw new CodegenException("{$where} names {$enum}, which is not a backed enum: it has no values to describe.");
        }
        if (!isset($this->names[$enum])) {
            $name = $this->claim($enum);
            $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases());
            $this->components[$name] = ['type' => \is_int($values[0] ?? null) ? 'integer' : 'string', 'enum' => $values];
        }

        return ['$ref' => '#/components/schemas/' . $this->names[$enum]];
    }

    /** @param class-string $class */
    private function claim(string $class): string
    {
        $short = Str::shortName($class);
        $clash = array_search($short, $this->names, true);
        if ($clash !== false) {
            throw new CodegenException("{$class} and {$clash} would both be described as `{$short}`. Rename one of them.");
        }
        $this->components[$short] = [];

        return $this->names[$class] = $short;
    }

    /** @return array<string, mixed>|null */
    private static function scalar(string $name): ?array
    {
        return match ($name) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'true' => ['type' => 'boolean', 'const' => true],
            'false' => ['type' => 'boolean', 'const' => false],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        if (!isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['anyOf'])) {
            return $schema;
        }
        if (($schema['type'] ?? null) === 'null') {
            return $schema;
        }
        if (isset($schema['type']) && \is_string($schema['type']) && \count($schema) <= 2 && !isset($schema['enum'])) {
            return [...$schema, 'type' => [$schema['type'], 'null']];
        }

        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private static function anyOf(array $parts): array
    {
        $parts = array_values(array_unique($parts, SORT_REGULAR));

        return \count($parts) === 1 ? $parts[0] : ['anyOf' => $parts];
    }
}
