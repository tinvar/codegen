<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

/**
 * The class names a file imports with `use`, for resolving the short names
 * its docblocks are written with.
 *
 * Reflection does not expose a class's imports, so they are read from its
 * file's tokens: the `use` statements at namespace level, in the namespace
 * the class is declared in. Function and constant imports are not class
 * names and are passed over, as are a class's `use` of traits and a
 * closure's `use` of variables, which sit deeper than namespace level.
 */
final class Imports
{
    /** @var array<string, array<string, array<string, string>>> file => namespace => alias => class */
    private static array $cache = [];

    /**
     * @param \ReflectionClass<object> $class
     * @return array<string, string> lower-cased alias => fully qualified name
     */
    public static function of(\ReflectionClass $class): array
    {
        $file = $class->getFileName();
        if ($file === false) {
            return [];
        }

        self::$cache[$file] ??= self::read($file);

        return self::$cache[$file][$class->getNamespaceName()] ?? [];
    }

    /** @return array<string, array<string, string>> */
    private static function read(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $imports = [];
        $namespace = '';
        // The depth namespace-level code is at: 0, or 1 inside a braced
        // `namespace X { }` block.
        $level = 0;
        $depth = 0;
        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                [$namespace, $i, $braced] = self::namespaceAt($tokens, $i + 1);
                $level = $braced ? $depth + 1 : $depth;
                if ($braced) {
                    $depth++;
                }
                continue;
            }

            if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || $token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_USE) && $depth === $level) {
                [$statement, $i] = self::statementAt($tokens, $i + 1);
                foreach (self::parse($statement) as $alias => $name) {
                    $imports[$namespace][$alias] = $name;
                }
            }
        }

        return $imports;
    }

    /**
     * @param array<int, \PhpToken> $tokens
     * @return array{string, int, bool} the name, the index of the token
     *                                   ending the declaration, and whether
     *                                   it opened a block
     */
    private static function namespaceAt(array $tokens, int $i): array
    {
        $name = '';
        for (; $i < \count($tokens); $i++) {
            $text = $tokens[$i]->text;
            if ($text === ';' || $text === '{') {
                return [$name, $i, $text === '{'];
            }
            if (!$tokens[$i]->isIgnorable()) {
                $name .= $text;
            }
        }

        return [$name, $i, false];
    }

    /**
     * The text of a statement up to its semicolon, without whitespace or comments.
     *
     * @param array<int, \PhpToken> $tokens
     * @return array{string, int}
     */
    private static function statementAt(array $tokens, int $i): array
    {
        $text = '';
        for (; $i < \count($tokens); $i++) {
            if ($tokens[$i]->text === ';') {
                break;
            }
            // Spaces kept only where they separate words: `NoteData as Remark`.
            $text .= $tokens[$i]->isIgnorable() ? ' ' : $tokens[$i]->text;
        }

        return [trim((string) preg_replace('/\s+/', ' ', $text)), $i];
    }

    /** @return array<string, string> */
    private static function parse(string $statement): array
    {
        if (preg_match('/^(?:function|const)\s/i', $statement) === 1) {
            return [];
        }

        $prefix = '';
        $items = $statement;
        if (preg_match('/^(.*?)\s*\{(.*)\}$/s', $statement, $m) === 1) {
            $prefix = rtrim($m[1], '\\ ') . '\\';
            $items = $m[2];
        }

        $imports = [];
        foreach (explode(',', $items) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $item) ?: [$item];
            $name = ltrim($prefix . trim($parts[0]), '\\');
            $segments = explode('\\', $name);
            $alias = trim($parts[1] ?? end($segments));

            $imports[strtolower($alias)] = $name;
        }

        return $imports;
    }
}
