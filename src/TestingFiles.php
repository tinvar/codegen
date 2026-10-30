<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

/**
 * The JavaScript half of the testing layer, as codegen writes it
 * (tinvar-spec.md, "Testing", "Client components"; ADR 0061):
 *
 *   fakes.ts         `fakeRackData({ name: 'R-14' })` for each Data
 *                    interface, and fakeActionResult(); an override is
 *                    laid over the defaults at any depth (withOverrides()).
 *   queries/....ts   A mock of each `@queries/*` module: the same functions,
 *                    built on mockQuery(), answering with a fake.
 *   actions/X.ts     A mock of each `@actions/*` module, built on
 *                    mockAction().
 *
 * All under a `testing/` directory beside the types, which
 * `tinvarTesting()` maps the two prefixes to in a Vitest run, and which no
 * build reaches: every one of them imports `@tinvar/react/testing`, which
 * the build refuses.
 *
 * A fake is made from the TypeScript type its field was emitted as, and
 * from nothing else: a string is the field's own name, a number 0, a list
 * empty, a field that may be null is null, an enum its first case. So no
 * value in one ever came from a database, a seeder or the environment -- a
 * fake is safe to commit, and says plainly that it is one. Each call builds
 * a new object, so a test that changes one changes no other test's.
 *
 * The grammar read is the one TypeMapper writes, and nothing else: a type
 * this cannot fake is a build failure here, not a fake that does not type.
 */
final class TestingFiles
{
    /** A module path segment, as `tinvarTesting()` resolves one: never `.` or `..`. */
    private const SEGMENT = '/^[A-Za-z0-9_-]+$/D';

    /** @var array<string, list<string>> fake name => the fakes its defaults call */
    private array $calls = [];

    /**
     * @param array<string, list<array{name: string, type: string}>> $interfaces interface name => its fields, as emitted
     * @param array<string, string|null>                            $enums      enum type name => its first value as a literal, null for none
     * @param string                                                $module     the module file's path from the types directory, as TypeScriptGenerator was given it
     */
    public function __construct(
        private readonly array $interfaces,
        private readonly array $enums,
        private readonly string $module,
        private readonly string $preamble,
    ) {}

    /**
     * @param list<array{name: string, module: string, function: string, args: list<array{name: string, type: string, optional?: bool}>, returns: string}> $queries
     * @param list<array{file: string, class: string, method: string, name: string, args: string}>                                                         $actions
     * @return array<string, string> path under testing/ => source
     */
    public function files(array $queries, array $actions, bool $hasActions): array
    {
        $files = ['fakes.ts' => $this->fakes($hasActions)];

        $byModule = [];
        foreach ($queries as $query) {
            $byModule[$query['module']][] = $query;
        }
        ksort($byModule);
        foreach ($byModule as $module => $members) {
            $files[self::queryFile((string) $module)] = $this->queryMock((string) $module, $members);
        }

        $byFile = [];
        foreach ($actions as $action) {
            $byFile[$action['file']][] = $action;
        }
        ksort($byFile);
        foreach ($byFile as $file => $members) {
            $files["actions/{$file}.ts"] = $this->actionMock($members);
        }

        return $files;
    }

    private function fakes(bool $hasActions): string
    {
        $bodies = [];
        $types = [];
        foreach ($this->interfaces as $name => $fields) {
            $fake = 'fake' . $name;
            $this->calls[$fake] = [];
            $lines = [];
            foreach ($fields as $field) {
                $lines[] = \sprintf(
                    '    %s: %s,',
                    TypeScriptGenerator::key($field['name'], "{$name}.{$field['name']}"),
                    $this->expression($field['type'], $field['name'], "{$name}.{$field['name']}", $fake),
                );
            }
            $types[] = $name;
            $bodies[] = "/** {$name} for a test: each field a placeholder, under the ones `overrides` gives, at any depth. */\n"
                . "export function {$fake}(overrides: Overrides<{$name}> = {}): {$name} {\n"
                . "  return withOverrides<{$name}>(\n    {\n" . implode("\n", array_map(static fn (string $l): string => "  {$l}", $lines)) . "\n    },\n    overrides,\n  );\n}\n";
        }
        $this->refuseCycles();

        if ($hasActions) {
            $types[] = 'ActionResult';
            $bodies[] = "/** What an action's endpoint answers when it has nothing to say: the mocks' default. */\n"
                . "export function fakeActionResult(overrides: Overrides<ActionResult> = {}): ActionResult {\n"
                . "  return withOverrides<ActionResult>({ value: null, flash: {}, revalidated: [], payloads: {} }, overrides);\n}\n";
        }
        sort($types);

        // The runtime is imported for what importing it does: the build
        // refuses a graph that reaches it (queryFunctions()), and it refuses
        // to load outside a Vitest worker. Without it nothing here would
        // stop a component importing a fake into a production page.
        $out = $this->preamble
            . "// Fakes of the PHP Data classes, for component tests. Every value is a placeholder made\n"
            . "// from the field's name and type alone: never a database's, a seeder's or a secret.\n"
            . "// The testing runtime is imported so that no build, and no process but a test, loads them.\n"
            . ($bodies === [] ? "\nimport '@tinvar/react/testing';\n" : "\nimport { withOverrides, type Overrides } from '@tinvar/react/testing';\n");
        if ($types !== []) {
            $out .= \sprintf(
                "import type { %s } from %s;\n",
                implode(', ', $types),
                TypeScriptGenerator::literal(self::from($this->module, 1), 'The path of the module file'),
            );
        }

        return $out . ($bodies === [] ? "\nexport {};\n" : "\n" . implode("\n", $bodies));
    }

    /**
     * @param list<array{name: string, module: string, function: string, args: list<array{name: string, type: string, optional?: bool}>, returns: string}> $members
     */
    private function queryMock(string $module, array $members): string
    {
        $depth = substr_count(self::queryFile($module), '/') + 1;
        $declarations = [];
        $named = '';
        $fakes = [];
        foreach ($members as $query) {
            $where = "The query `{$query['name']}`";
            $args = implode('; ', array_map(
                static fn (array $a): string => TypeScriptGenerator::key($a['name'], $where)
                    . (($a['optional'] ?? false) ? '?' : '') . ": {$a['type']}",
                $query['args'],
            ));
            $expression = $this->expression($query['returns'], $query['function'], $where, null);
            $fakes = [...$fakes, ...self::fakesIn($expression)];
            $named .= "{$args} {$query['returns']} ";
            $declarations[] = \sprintf(
                "/** Stands in for the PHP query `%s`: records each call, and answers with a fake unless the test says otherwise. */\n"
                . "export const %s = mockQuery<{ %s }, %s>(%s, () => %s);\n",
                TypeScriptGenerator::comment($query['name']),
                $query['function'],
                $args,
                $query['returns'],
                TypeScriptGenerator::literal($query['name'], $where),
                $expression,
            );
        }

        return $this->mockModule("A mock of {$module}, for component tests.", 'mockQuery', $depth, $named, $fakes, $declarations);
    }

    /** @param list<array{file: string, class: string, method: string, name: string, args: string}> $members */
    private function actionMock(array $members): string
    {
        $declarations = [];
        $named = '';
        foreach ($members as $action) {
            $named .= $action['args'] . ' ';
            $declarations[] = \sprintf(
                "/** Stands in for the PHP action `%s`: records each call, and answers as the test says. */\n"
                . "export const %s = mockAction<{ %s }, ActionResult>(%s, fakeActionResult);\n",
                TypeScriptGenerator::comment($action['name']),
                $action['method'],
                $action['args'],
                TypeScriptGenerator::literal($action['name'], "The action `{$action['name']}`"),
            );
        }

        return $this->mockModule(
            "A mock of the actions of {$members[0]['class']}, for component tests.",
            'mockAction',
            2,
            $named . ' ActionResult',
            ['fakeActionResult'],
            $declarations,
        );
    }

    /**
     * @param list<string> $fakes
     * @param list<string> $declarations
     */
    private function mockModule(string $about, string $runtime, int $depth, string $named, array $fakes, array $declarations): string
    {
        $types = [];
        foreach ([...array_keys($this->interfaces), 'ActionResult', ...array_keys($this->enums)] as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $named) === 1) {
                $types[] = $name;
            }
        }
        $types = array_values(array_unique($types));
        sort($types);
        $fakes = array_values(array_unique($fakes));
        sort($fakes);

        $out = $this->preamble . '// ' . TypeScriptGenerator::comment($about) . "\n\n"
            . \sprintf("import { %s } from '@tinvar/react/testing';\n", $runtime);
        if ($types !== []) {
            $out .= \sprintf(
                "import type { %s } from %s;\n",
                implode(', ', $types),
                TypeScriptGenerator::literal(self::from($this->module, $depth), 'The path of the module file'),
            );
        }
        if ($fakes !== []) {
            $out .= \sprintf("import { %s } from '%s';\n", implode(', ', $fakes), $depth === 1 ? './fakes' : str_repeat('../', $depth - 1) . 'fakes');
        }

        return $out . "\n" . implode("\n", $declarations);
    }

    /**
     * Where a `@queries/*` module's mock goes: `@queries` itself at
     * queries.ts, and `@queries/a/b` at queries/a/b.ts, as `tinvarTesting()`
     * resolves them. A segment that is not a plain name is refused rather
     * than written somewhere else.
     */
    private static function queryFile(string $module): string
    {
        if ($module === '@queries') {
            return 'queries.ts';
        }
        $segments = explode('/', substr($module, \strlen('@queries/')));
        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new CodegenException("The module {$module} cannot be mocked: `{$segment}` is not a plain path segment. Rename the query.");
            }
        }

        return 'queries/' . implode('/', $segments) . '.ts';
    }

    /**
     * The module file's path from a file $levels directories under the types
     * directory. A bare specifier -- a package -- is the same from anywhere.
     */
    private static function from(string $module, int $levels): string
    {
        $up = str_repeat('../', $levels);

        return match (true) {
            str_starts_with($module, './') => $up . substr($module, 2),
            str_starts_with($module, '../') => $up . $module,
            default => $module,
        };
    }

    /** @return list<string> the fakes an expression calls */
    private static function fakesIn(string $expression): array
    {
        preg_match_all('/\b(fake[A-Za-z0-9_$]+)\(/', $expression, $matches);

        return $matches[1];
    }

    /**
     * The default value of one emitted type, as TypeScript source.
     *
     * @param string      $field what a string or a marker's path is named after
     * @param string|null $fake  the fake this is a default of, to record what it calls
     */
    private function expression(string $type, string $field, string $where, ?string $fake): string
    {
        $members = self::union($type);

        // Absent is a value the type allows, and the one that ends a
        // structure referring to itself.
        if (\in_array('null', $members, true)) {
            return 'null';
        }

        // The value side of `T | Rescued` and `T | Unrequested`: written
        // first, and what a component is mostly tested with.
        $member = $members[0];

        if (str_ends_with($member, '[]')) {
            return '[]';
        }

        // A #[Merge] into a path of a Data object: the object's own fake,
        // with the list under the path an empty merge field (ADR 0120).
        if (preg_match('/^Omit<([A-Za-z_$][\w$]*), \'([A-Za-z_][A-Za-z0-9_]*)\'> & \{ \2: MergeField<.*> \}$/s', $member, $merged) === 1) {
            if (!isset($this->interfaces[$merged[1]])) {
                throw new CodegenException("{$where} is typed {$type}, which codegen cannot make a fake of.");
            }
            $path = TypeScriptGenerator::literal("{$field}.{$merged[2]}", $where);

            return '{ ...' . $this->call('fake' . $merged[1], $fake)
                . ", {$merged[2]}: { \$merge: {$path}, mode: 'append', reset: false, matchOn: null, items: [] } }";
        }

        $generic = self::generic($member);
        if ($generic !== null) {
            [$name, $inner] = $generic;
            $path = TypeScriptGenerator::literal($field, $where);

            return match ($name) {
                'Record' => '{}',
                'OnceField' => "{ \$once: {$path}, ttl: null, tags: [], value: " . $this->expression($inner, $field, $where, $fake) . ' }',
                'MergeField' => "{ \$merge: {$path}, mode: 'append', reset: false, matchOn: null, items: [] }",
                'ScrollField' => "{ \$scroll: {$path}, reset: false, page: 1, previous: null, next: null, total: 0, perPage: 15, pageName: 'page', matchOn: null, items: [] }",
                'CursorScrollField' => "{ \$scroll: {$path}, reset: false, cursor: null, previous: null, next: null, total: null, perPage: 15, pageName: 'cursor', matchOn: null, items: [] }",
                default => throw new CodegenException("{$where} is typed {$type}, which codegen cannot make a fake of."),
            };
        }

        return match (true) {
            $member === 'string' => TypeScriptGenerator::literal($field, $where),
            $member === 'number' => '0',
            $member === 'boolean', $member === 'false' => 'false',
            $member === 'true' => 'true',
            $member === 'unknown' => 'null',
            isset($this->interfaces[$member]) => $this->call('fake' . $member, $fake),
            \array_key_exists($member, $this->enums) => $this->enums[$member]
                ?? throw new CodegenException("{$where} is typed {$member}, an enum with no cases, which no value can be."),
            default => throw new CodegenException("{$where} is typed {$type}, which codegen cannot make a fake of."),
        };
    }

    private function call(string $callee, ?string $fake): string
    {
        if ($fake !== null) {
            $this->calls[$fake][] = $callee;
        }

        return "{$callee}()";
    }

    /**
     * A Data class whose defaults need itself -- directly, or through
     * others, by fields that cannot be null or empty -- has no finite fake,
     * and could not have been constructed in PHP either.
     */
    private function refuseCycles(): void
    {
        $state = [];
        $visit = function (string $fake, array $path) use (&$visit, &$state): void {
            if (($state[$fake] ?? null) === 'done') {
                return;
            }
            if (($state[$fake] ?? null) === 'open') {
                $cycle = [...\array_slice($path, (int) array_search($fake, $path, true)), $fake];
                throw new CodegenException(
                    'These Data classes need each other through fields that cannot be null or empty, so none can be made: '
                    . implode(' -> ', array_map(static fn (string $f): string => substr($f, 4), $cycle)) . '.',
                );
            }
            $state[$fake] = 'open';
            foreach ($this->calls[$fake] ?? [] as $callee) {
                $visit($callee, [...$path, $fake]);
            }
            $state[$fake] = 'done';
        };
        foreach (array_keys($this->calls) as $fake) {
            $visit($fake, []);
        }
    }

    /**
     * A type's top-level union members: `A | B<C | D> | null` is three.
     *
     * @return non-empty-list<string>
     */
    private static function union(string $type): array
    {
        $members = [];
        $depth = 0;
        $start = 0;
        $length = \strlen($type);
        for ($i = 0; $i < $length; $i++) {
            $char = $type[$i];
            if ($char === '<' || $char === '{' || $char === '(') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')') {
                $depth--;
            } elseif ($char === '|' && $depth === 0) {
                $members[] = trim(substr($type, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $members[] = trim(substr($type, $start));

        return $members;
    }

    /** @return array{string, string}|null `Name<inner>`'s name and inner type */
    private static function generic(string $member): ?array
    {
        if (preg_match('/^([A-Za-z_$][A-Za-z0-9_$]*)<(.*)>$/s', $member, $m) !== 1) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}
