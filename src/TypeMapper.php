<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Data\Data;
use Tinvar\Support\Lazy;
use Tinvar\Data\Scroll;
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

    /** @var array<string, string> what that class's file imports with `use`; see Imports */
    private array $imports = [];

    /** Whether a Scroll has been mapped; see mappedScroll(). */
    private bool $scrolls = false;

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
     * @param array<string, string> $imports the declaring file's `use`
     *                                       imports, from Imports::of()
     */
    public function map(
        \ReflectionType $type,
        ?string $docHint,
        string $where,
        ?string $namespace = null,
        array $imports = [],
    ): string {
        return $this->within($namespace, $imports, fn (): string => $this->mapType($type, $docHint, $where));
    }

    /**
     * @template T
     * @param array<string, string> $imports
     * @param \Closure(): T $map
     * @return T
     */
    private function within(?string $namespace, array $imports, \Closure $map): mixed
    {
        $previous = [$this->namespace, $this->imports];
        if ($namespace !== null) {
            $this->namespace = $namespace;
            $this->imports = $imports;
        }

        try {
            return $map();
        } finally {
            [$this->namespace, $this->imports] = $previous;
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
            // `string|false`, and true and false on their own (PHP 8.2), are
            // the literal types TypeScript has too. They were refused as
            // class names.
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            'mixed' => 'unknown',
            'array' => $this->mapArray($docHint, $where),
            Scroll::class => $this->mapScroll($docHint, $where),
            // Reflection names a class in full; there is nothing to resolve.
            // Lazy by is_a(), so its name from before the split, an alias of
            // it, is read as Lazy too.
            default => is_a($name, Lazy::class, true)
                ? $this->mapLazy($docHint, $where)
                : $this->mapClass($name, $where, qualified: true),
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

    /**
     * `Lazy<T>` becomes `T`: the encoder resolves a Lazy and sends its value.
     *
     * The spec's codegen table says `Promise<T>`, which is what streaming an
     * unresolved Lazy (Level 3 lazy loading) will send. Nothing sends that
     * yet, and a type promising it had components awaiting a plain list.
     * The type follows what is produced, and changes when that does.
     */
    private function mapLazy(?string $docHint, string $where): string
    {
        $element = DocBlock::lazyType($docHint);
        if ($element === null) {
            throw new CodegenException(
                "No value type for the Lazy field at {$where}. Add `@var Lazy<list<UnitData>>`.",
            );
        }

        return $this->mapElement($element, $where);
    }

    /**
     * `Scroll<T>` becomes `ScrollField<T>`: one page of items, and where
     * they sit among the pages -- what the encoder sends for a Scroll.
     */
    private function mapScroll(?string $docHint, string $where): string
    {
        $element = DocBlock::scrollType($docHint);
        if ($element === null) {
            throw new CodegenException(
                "No item type for the Scroll field at {$where}. Add `@var Scroll<OrderData>`.",
            );
        }

        $this->scrolls = true;

        return sprintf('ScrollField<%s>', $this->mapElement($element, $where));
    }

    /** Whether any Scroll was mapped, so the module needs the ScrollField type. */
    public function mappedScroll(): bool
    {
        return $this->scrolls;
    }

    /**
     * Maps a docblock type string, for places where no ReflectionType exists.
     *
     * @param array<string, string> $imports
     */
    public function mapHint(string $hint, string $where, ?string $namespace = null, array $imports = []): string
    {
        return $this->within($namespace, $imports, fn (): string => $this->mapElement($hint, $where));
    }

    private function mapElement(string $element, string $where): string
    {
        $element = trim($element);

        if (str_ends_with($element, '[]')) {
            return $this->mapElement(substr($element, 0, -2), $where) . '[]';
        }

        // A Scroll inside a Lazy, say: the same shape as one in a field.
        $scroll = DocBlock::scrollType($element);
        if ($scroll !== null) {
            $this->scrolls = true;

            return sprintf('ScrollField<%s>', $this->mapElement($scroll, $where));
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

    /** @param bool $qualified whether the name is already in full, as Reflection gives one */
    private function mapClass(string $name, string $where, bool $qualified = false): string
    {
        if (isset($this->names[$name])) {
            return $this->names[$name];
        }

        // Docblocks are written with the names the file uses, so they are
        // resolved as PHP resolves that file's names: through its `use`
        // imports, then its namespace. Only the namespace was tried, so a
        // class imported from elsewhere was refused unless it happened to
        // have been emitted already.
        $resolved = $qualified ? null : $this->resolve($name);
        if ($resolved !== null && isset($this->names[$resolved])) {
            return $this->names[$resolved];
        }
        if ($resolved !== null && class_exists($resolved) && is_subclass_of($resolved, Data::class)) {
            return $this->register($resolved);
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

        throw new CodegenException(
            "Cannot map '{$name}' at {$where}. Only Data objects, scalars, lists and maps cross the boundary; "
            . 'map it to a Data object first.',
        );
    }

    /**
     * A docblock name as the file it is written in means it: from the global
     * namespace with a leading backslash, through an import when its first
     * segment is one, and otherwise in the file's namespace.
     */
    private function resolve(string $name): ?string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $segments = explode('\\', $name, 2);
        $imported = $this->imports[strtolower($segments[0])] ?? null;
        if ($imported !== null) {
            return isset($segments[1]) ? "{$imported}\\{$segments[1]}" : $imported;
        }

        return $this->namespace === null ? null : "{$this->namespace}\\{$name}";
    }
}
