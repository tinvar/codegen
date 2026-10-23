<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Data\Data;
use Tinvar\Data\Lazy;
use Tinvar\Support\Str;

/**
 * Maps a PHP type to its TypeScript equivalent.
 *
 * PHP's array type says nothing about its contents, so the docblock supplies
 * it: `@var list<UnitData>` becomes `UnitData[]`. A Data field with no element
 * type is an error rather than `unknown[]` -- the point of codegen is that
 * nothing is written twice, and a silent `unknown` puts it back on the author.
 */
final class TypeMapper
{
    /** @var array<class-string, string> Data class => emitted interface name */
    private array $names = [];

    /** Namespace of the class currently being mapped, for short docblock names. */
    private ?string $namespace = null;

    /** @param class-string $class */
    public function register(string $class): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }

        // Interfaces are emitted under short names, so two Data classes that
        // share one became two `export interface X` blocks with different
        // bodies -- a TypeScript merge of both, describing neither.
        $short = Str::shortName($class);
        $clash = array_search($short, $this->names, true);
        if ($clash !== false) {
            throw new CodegenException(
                "{$class} and {$clash} would both be emitted as `{$short}`. Rename one of them.",
            );
        }

        return $this->names[$class] = $short;
    }

    /** @return array<class-string, string> */
    public function registered(): array
    {
        return $this->names;
    }

    /**
     * @param string|null $namespace the declaring class's namespace, used to
     *                               resolve short names written in docblocks
     */
    public function map(\ReflectionType $type, ?string $docHint, string $where, ?string $namespace = null): string
    {
        $previous = $this->namespace;
        $this->namespace = $namespace ?? $previous;

        try {
            return $this->mapType($type, $docHint, $where);
        } finally {
            $this->namespace = $previous;
        }
    }

    private function mapType(\ReflectionType $type, ?string $docHint, string $where): string
    {
        if ($type instanceof \ReflectionUnionType) {
            $parts = array_map(
                fn (\ReflectionType $t): string => $this->mapType($t, $docHint, $where),
                $type->getTypes(),
            );

            return implode(' | ', array_unique($parts));
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw new CodegenException("Cannot map intersection type at {$where}.");
        }

        $mapped = $this->mapNamed($type->getName(), $docHint, $where);

        // `unknown` already includes null; PHP reports mixed as nullable.
        return $type->allowsNull() && $mapped !== 'null' && $mapped !== 'unknown' ? "{$mapped} | null" : $mapped;
    }

    private function mapNamed(string $name, ?string $docHint, string $where): string
    {
        return match ($name) {
            'string' => 'string',
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'null' => 'null',
            'mixed' => 'unknown',
            'array' => $this->mapArray($docHint, $where),
            Lazy::class => $this->mapLazy($docHint, $where),
            default => $this->mapClass($name, $where),
        };
    }

    private function mapArray(?string $docHint, string $where): string
    {
        $element = DocBlock::elementType($docHint);
        if ($element === null) {
            throw new CodegenException(
                "No element type for the array at {$where}. "
                . 'Add a docblock such as `@var list<UnitData>` so the generated type is not `unknown[]`.',
            );
        }

        // A map shape: array<string, T> becomes Record<string, T>.
        if ($element['key'] !== null) {
            return sprintf('Record<%s, %s>', $element['key'], $this->mapElement($element['value'], $where));
        }

        return $this->mapElement($element['value'], $where) . '[]';
    }

    /** `Lazy<T>` becomes `Promise<T>`, as the spec's codegen table says. */
    private function mapLazy(?string $docHint, string $where): string
    {
        $element = DocBlock::lazyType($docHint);
        if ($element === null) {
            throw new CodegenException(
                "No value type for the Lazy field at {$where}. Add `@var Lazy<list<UnitData>>`.",
            );
        }

        return 'Promise<' . $this->mapElement($element, $where) . '>';
    }

    /** Maps a docblock type string, for places where no ReflectionType exists. */
    public function mapHint(string $hint, string $where, ?string $namespace = null): string
    {
        $previous = $this->namespace;
        $this->namespace = $namespace ?? $previous;

        try {
            return $this->mapElement($hint, $where);
        } finally {
            $this->namespace = $previous;
        }
    }

    private function mapElement(string $element, string $where): string
    {
        $element = trim($element);

        if (str_ends_with($element, '[]')) {
            return $this->mapElement(substr($element, 0, -2), $where) . '[]';
        }

        $inner = DocBlock::elementType($element);
        if ($inner !== null) {
            return $inner['key'] !== null
                ? sprintf('Record<%s, %s>', $inner['key'], $this->mapElement($inner['value'], $where))
                : $this->mapElement($inner['value'], $where) . '[]';
        }

        return match ($element) {
            'string' => 'string',
            'int', 'float', 'number' => 'number',
            'bool', 'true', 'false' => 'boolean',
            'mixed' => 'unknown',
            'null' => 'null',
            default => $this->mapClass($element, $where),
        };
    }

    private function mapClass(string $name, string $where): string
    {
        if (isset($this->names[$name])) {
            return $this->names[$name];
        }

        // Short names from docblocks: match a registered class by its tail.
        foreach ($this->names as $class => $emitted) {
            if ($emitted === $name || Str::shortName($class) === $name) {
                return $emitted;
            }
        }

        if (class_exists($name) && is_subclass_of($name, Data::class)) {
            return $this->register($name);
        }

        // Docblocks are written with short names; resolve against the namespace
        // of the class the field belongs to.
        if ($this->namespace !== null && !str_contains($name, '\\')) {
            $qualified = $this->namespace . '\\' . $name;
            if (class_exists($qualified) && is_subclass_of($qualified, Data::class)) {
                return $this->register($qualified);
            }
        }

        throw new CodegenException(
            "Cannot map '{$name}' at {$where}. Only Data objects, scalars, lists and maps cross the boundary; "
            . 'map it to a Data object first.',
        );
    }
}
