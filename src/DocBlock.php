<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

/**
 * Just enough docblock reading to recover element types PHP's own types drop.
 *
 * Deliberately small: it understands `list<T>`, `array<T>`, `array<K, V>`,
 * `T[]`, `Lazy<T>`, unions of those and `?T`, and nothing else.
 */
final class DocBlock
{
    /** Pulls the `@var`/`@param` type for a named parameter, or the sole `@var`. */
    public static function typeFor(string|false $docComment, ?string $parameter = null): ?string
    {
        if ($docComment === false || $docComment === '') {
            return null;
        }

        if ($parameter !== null
            && preg_match('/@param\s+(.+?)\s+\$' . preg_quote($parameter, '/') . '\b/', $docComment, $m) === 1) {
            return trim($m[1]);
        }

        return self::tagType($docComment, 'var');
    }

    /** Pulls the `@return` type. */
    public static function returnType(string|false $docComment): ?string
    {
        if ($docComment === false || $docComment === '') {
            return null;
        }

        return self::tagType($docComment, 'return');
    }

    /**
     * The type a tag names: what follows it up to the first space outside
     * brackets. The rest of the line is the tag's description -- `@var
     * list<RackData> the racks on this page` -- and it was read as part of
     * the type, which then mapped to nothing.
     */
    private static function tagType(string $docComment, string $tag): ?string
    {
        if (preg_match('/@' . $tag . '\s+(.*)$/m', $docComment, $m) !== 1) {
            return null;
        }

        $text = (string) preg_replace('/\*\/.*$/', '', $m[1]);
        $type = '';
        $depth = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $character = $text[$i];

            if ($depth === 0 && ctype_space($character)) {
                $next = $i;
                while ($next < $length && ctype_space($text[$next])) {
                    $next++;
                }

                // A union written with spaces, `A | B`, goes on past them.
                if (($text[$next] ?? '') === '|' || str_ends_with($type, '|')) {
                    $i = $next - 1;
                    continue;
                }

                break;
            }

            if (str_contains('<{([', $character)) {
                $depth++;
            } elseif (str_contains('>})]', $character)) {
                $depth--;
            }

            $type .= $character;
        }

        return $type === '' ? null : $type;
    }

    /**
     * @return array{key: string|null, value: string}|null
     */
    public static function elementType(?string $hint): ?array
    {
        if ($hint === null) {
            return null;
        }
        $hint = self::withoutNull(trim($hint));

        // A union of arrays is not one array: TypeMapper maps it part by part.
        if (\count(self::union($hint)) > 1) {
            return null;
        }

        if (preg_match('/^(?:list|non-empty-list)<(.+)>$/s', $hint, $m) === 1) {
            return ['key' => null, 'value' => $m[1]];
        }

        if (preg_match('/^(?:array|non-empty-array)<(.+)>$/s', $hint, $m) === 1) {
            $inner = self::splitTopLevel($m[1]);

            return \count($inner) === 2
                ? ['key' => self::keyType($inner[0]), 'value' => $inner[1]]
                : ['key' => null, 'value' => $inner[0]];
        }

        if (str_ends_with($hint, '[]')) {
            return ['key' => null, 'value' => substr($hint, 0, -2)];
        }

        return null;
    }

    public static function lazyType(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        return preg_match('/Lazy<(.+)>$/s', self::withoutNull($hint), $m) === 1 ? $m[1] : null;
    }

    /** The item type of `Scroll<T>`, written with or without its namespace. */
    public static function scrollType(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        return preg_match('/^(?:\\\\?Tinvar\\\\Data\\\\)?Scroll<(.+)>$/s', self::withoutNull($hint), $m) === 1 ? $m[1] : null;
    }

    /** The item type of `CursorScroll<T>`, written with or without its namespace. */
    public static function cursorScrollType(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        return preg_match('/^(?:\\\\?Tinvar\\\\Data\\\\)?CursorScroll<(.+)>$/s', self::withoutNull($hint), $m) === 1 ? $m[1] : null;
    }

    /**
     * The members of a union, split on the `|`s outside brackets: `int|null`
     * is two, `list<int|null>` one.
     *
     * @return list<string>
     */
    public static function union(string $hint): array
    {
        return self::splitTopLevel($hint, '|');
    }

    /**
     * `list<T>|null` -- or `?list<T>` -- is how a nullable array is written;
     * whether it may be null comes from the PHP type, so the docblock's
     * `null` is dropped. It was read as one opaque hint, and every nullable
     * list was refused.
     */
    public static function withoutNull(string $hint): string
    {
        $hint = trim($hint);
        if (str_starts_with($hint, '?')) {
            return trim(substr($hint, 1));
        }

        $parts = array_values(array_filter(
            self::splitTopLevel($hint, '|'),
            static fn (string $part): bool => $part !== 'null',
        ));

        return \count($parts) === 1 ? $parts[0] : $hint;
    }

    private static function keyType(string $key): string
    {
        return match (trim($key)) {
            'int' => 'number',
            'array-key' => 'string | number',
            default => 'string',
        };
    }

    /** @return list<string> splits `string, Foo<int, int>` without cutting inside <>, {} or () */
    private static function splitTopLevel(string $input, string $separator = ','): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($input) as $character) {
            if (str_contains('<{(', $character)) {
                $depth++;
            } elseif (str_contains('>})', $character)) {
                $depth--;
            } elseif ($character === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $parts[] = trim($current);

        return $parts;
    }
}
