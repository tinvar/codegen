<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

/**
 * Just enough docblock reading to recover element types PHP's own types drop.
 *
 * Deliberately small: it understands `list<T>`, `array<T>`, `array<K, V>`,
 * `T[]` and `Lazy<T>`, and nothing else.
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

        if (preg_match('/@var\s+(.+?)(?:\s+\$|\s*\*\/|\R)/', $docComment, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /** Pulls the `@return` type. */
    public static function returnType(string|false $docComment): ?string
    {
        if ($docComment === false || $docComment === '') {
            return null;
        }

        return preg_match('/@return\s+(.+?)(?:\s*\*\/|\R)/', $docComment, $m) === 1 ? trim($m[1]) : null;
    }

    /**
     * @return array{key: string|null, value: string}|null
     */
    public static function elementType(?string $hint): ?array
    {
        if ($hint === null) {
            return null;
        }
        $hint = trim($hint);

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

        return preg_match('/Lazy<(.+)>$/s', trim($hint), $m) === 1 ? $m[1] : null;
    }

    private static function keyType(string $key): string
    {
        return match (trim($key)) {
            'int' => 'number',
            'array-key' => 'string | number',
            default => 'string',
        };
    }

    /** @return list<string> splits `string, Foo<int, int>` without cutting inside <> */
    private static function splitTopLevel(string $input): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($input) as $character) {
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
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
