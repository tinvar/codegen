<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Orm\Model;
use Tinvar\Routing\RouteKind;
use Tinvar\Support\Str;

/**
 * Emits TypeScript for the Data objects a build uses, plus typed `@queries/*`
 * stubs (tinvar-spec.md, "Data boundary and TypeScript codegen").
 *
 * Fields come from DataShape, the same source the encoder walks, so the
 * generated interface always describes the payload that is actually produced.
 *
 * A build writes two files side by side; see files(). They were one, and
 * one file cannot be both: the interfaces are imported by path, so it has
 * top-level exports -- and in a file with top-level exports `declare module
 * '@queries/racks'` is an augmentation of a module that does not exist, not
 * a declaration of one (TS2664), so every import of a query failed to
 * resolve (TS2307).
 */
final class TypeScriptGenerator
{
    /** Where the generated route() gets its runtime. */
    public const ROUTE_RUNTIME = '@tinvar/react/route';

    /** What an identifier written into the output must look like, exactly. */
    private const IDENTIFIER = '/^[A-Za-z_$][A-Za-z0-9_$]*$/D';

    /**
     * Words a function or interface cannot be named in a module: the reserved
     * words, the strict-mode ones (modules are strict), and the two names
     * strict mode will not bind.
     */
    private const RESERVED = [
        'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default', 'delete',
        'do', 'else', 'enum', 'export', 'extends', 'false', 'finally', 'for', 'function', 'if', 'import',
        'in', 'instanceof', 'new', 'null', 'return', 'super', 'switch', 'this', 'throw', 'true', 'try',
        'typeof', 'var', 'void', 'while', 'with', 'implements', 'interface', 'let', 'package', 'private',
        'protected', 'public', 'static', 'yield', 'arguments', 'eval',
    ];

    private TypeMapper $types;

    /** @var array<string, string> class => emitted interface body */
    private array $interfaces = [];

    /**
     * @param string $module how the ambient file imports the module file: the
     *                       module's path relative to it, without extension
     */
    public function __construct(
        private readonly string $header = '',
        private readonly string $module = './generated',
    ) {
        $this->types = new TypeMapper();
    }

    /**
     * The module file alone; see files() for what a build writes.
     *
     * @param list<class-string<Data>> $dataClasses
     * @param list<class-string>       $queryClasses
     * @param list<RouteTypes>         $routes
     * @param list<CompiledAction>     $actions
     */
    public function generate(
        array $dataClasses,
        array $queryClasses = [],
        array $routes = [],
        array $actions = [],
    ): string {
        return $this->files($dataClasses, $queryClasses, $routes, $actions)['module'];
    }

    /**
     * The two files a build writes, side by side.
     *
     *   module   A TypeScript module (`generated.ts`): the Data interfaces,
     *            PageProps and LayoutProps, ActionResult, and route() -- a
     *            real function, built on ROUTE_RUNTIME, so importing it
     *            resolves at build time as well as type-checking.
     *   ambient  A declaration file (`modules.d.ts`) with no top-level import
     *            or export, holding `declare module '@queries/...'` and
     *            `'@actions/...'`. Being a script is what makes each one a
     *            declaration. Each module takes the types it names from the
     *            module file, by the path given to the constructor.
     *
     * @param list<class-string<Data>> $dataClasses
     * @param list<class-string>       $queryClasses
     * @param list<RouteTypes>         $routes
     * @param list<CompiledAction>     $actions
     * @return array{module: string, ambient: string}
     */
    public function files(
        array $dataClasses,
        array $queryClasses = [],
        array $routes = [],
        array $actions = [],
    ): array {
        $this->interfaces = [];
        $this->types = new TypeMapper();

        foreach ($dataClasses as $class) {
            $this->emitData($class);
        }

        // A route's loader return type is a Data class the components need.
        foreach ($routes as $route) {
            foreach ($route->dataClasses as $class) {
                $this->emitData($class);
            }
        }

        // Described before the set of Data classes closes: a query's result
        // and an action's form-request fields can name Data classes too.
        $queries = array_map($this->describeQuery(...), $queryClasses);
        $described = array_map($this->describeAction(...), $actions);

        // Emitting a Data class can pull in others it references; keep going
        // until the set closes.
        while (($pending = array_diff_key($this->types->registered(), $this->interfaces)) !== []) {
            foreach (array_keys($pending) as $class) {
                $this->emitData($class);
            }
        }

        // Both would be `export interface ActionResult` in one module, which
        // TypeScript merges into a type describing neither.
        if ($actions !== [] && \in_array('ActionResult', $this->types->registered(), true)) {
            throw new CodegenException(
                'A Data class named ActionResult would be emitted beside the action result type of the same name. Rename it.',
            );
        }

        return [
            'module' => $this->renderModule($routes, $actions !== []),
            'ambient' => $this->renderAmbient($queries, $described),
        ];
    }

    /** @param class-string $class */
    private function emitData(string $class): void
    {
        if (isset($this->interfaces[$class])) {
            return;
        }

        if (!is_subclass_of($class, Data::class)) {
            throw new CodegenException(
                "{$class} is not a Data object. Only Data objects cross to the renderer, so only they get types.",
            );
        }

        $name = self::identifier($this->types->register($class), $class);
        $this->interfaces[$class] = '';   // claim the slot before recursing

        $reflection = new \ReflectionClass($class);
        $constructorDoc = $reflection->getConstructor()?->getDocComment() ?? false;
        $lines = [];

        foreach (DataShape::fieldsOf($class) as $property) {
            $field = $property->getName();
            $where = "{$class}::\${$field}";

            // Data classes are readonly, and PHP requires a readonly property to be typed.
            $type = $property->getType() ?? throw new CodegenException("{$where} has no type; Data fields must be typed.");

            $hint = DocBlock::typeFor($property->getDocComment(), $field)
                ?? DocBlock::typeFor($constructorDoc, $field);

            // Its docblock's names mean what they mean in the file that
            // declares it.
            $declaring = $property->getDeclaringClass();
            $mapped = $this->types->map($type, $hint, $where, $declaring->getNamespaceName(), Imports::of($declaring));

            $lines[] = sprintf('  %s: %s;', self::key($field, $where), $mapped);
        }

        $this->interfaces[$class] = sprintf(
            "export interface %s {\n%s\n}\n",
            $name,
            implode("\n", $lines),
        );
    }

    /**
     * @param class-string $class
     * @return array{name: string, module: string, function: string, args: list<array{name: string, type: string, optional?: bool}>, returns: string}
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
                    'type' => match (true) {
                        $type === null => 'unknown',
                        // A model crosses as its key here too, as QueryRegistry
                        // resolves it. Mapped as a class, the spec's own
                        // RackStats was refused as a model on the boundary.
                        self::isModel($type) => $type->allowsNull() ? 'string | null' : 'string',
                        default => $this->types->map($type, null, "{$class}::__invoke(\${$parameter->getName()})"),
                    },
                    // What QueryRegistry::parametersFor() lets a caller leave
                    // out: a default, or a type that allows null. Typed as
                    // required, every call had to pass them.
                    'optional' => $parameter->isDefaultValueAvailable() || $parameter->allowsNull(),
                ];
            }
        }

        $returns = $this->returnTypeOf($reflection, $class);

        return [
            'name' => $wireName,
            'module' => self::module(self::moduleFor($wireName), "The query `{$wireName}` ({$class})"),
            'function' => self::identifier(self::functionName($wireName), "The query `{$wireName}` ({$class})"),
            'args' => $args,
            'returns' => $returns,
        ];
    }

    /** The test QueryRegistry applies to find a plain query's model arguments. */
    private static function isModel(\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType
            && !$type->isBuiltin()
            && is_subclass_of($type->getName(), Model::class);
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
                    "{$class}::loadMany() needs an `@return list<SomeData>` docblock so its call site can be typed.",
                );
            }

            return $this->types->mapHint(
                $element['value'],
                "{$class}::loadMany()",
                $reflection->getNamespaceName(),
                Imports::of($reflection),
            );
        }

        $invoke = $reflection->getMethod('__invoke');
        $type = $invoke->getReturnType();
        if ($type === null) {
            throw new CodegenException("{$class}::__invoke() needs a return type.");
        }

        // `array` names no element type; the `@return list<SomeData>` does,
        // read as loadMany()'s is. Without it a list result was refused.
        return $this->types->map(
            $type,
            DocBlock::returnType($invoke->getDocComment()),
            "{$class}::__invoke()",
            $reflection->getNamespaceName(),
            Imports::of($reflection),
        );
    }

    /** `racks.unitLoad` -> `@queries/racks`; a name with no prefix -> `@queries`. */
    private static function moduleFor(string $wireName): string
    {
        $dot = strrpos($wireName, '.');

        return $dot === false ? '@queries' : '@queries/' . str_replace('.', '/', substr($wireName, 0, $dot));
    }

    private static function functionName(string $wireName): string
    {
        $parts = explode('.', $wireName);
        $tail = array_pop($parts);

        return lcfirst($tail);
    }

    /**
     * @return array{module: string, function: string}
     */
    private function describeAction(CompiledAction $action): array
    {
        $where = "The action `{$action->name}`";
        $arguments = [];
        foreach ($action->models as $parameter => $_) {
            $arguments[] = self::key($parameter, $where) . ': string';   // a model crosses as its id
        }
        foreach ($action->formRequests as $class) {
            foreach (DataShape::fieldsOf($class) as $field) {
                $type = $field->getType();
                $arguments[] = \sprintf(
                    '%s: %s',
                    self::key($field->getName(), "{$class}::\${$field->getName()}"),
                    $type === null ? 'unknown' : $this->types->map($type, null, $class, (new \ReflectionClass($class))->getNamespaceName()),
                );
            }
        }

        $module = self::module('@actions/' . Str::shortName($action->class), "The actions of {$action->class}");

        return [
            'module' => $module,
            'function' => \sprintf(
                "  /** Posts to the PHP action `%s`. */\n  export function %s(args: { %s }): Promise<ActionResult>;\n",
                self::comment($action->name),
                self::identifier($action->method, "{$action->class}::{$action->method}()"),
                implode('; ', $arguments),
            ),
        ];
    }

    private function preamble(string $about): string
    {
        $out = $this->header === '' ? '' : $this->header . "\n";

        return $out . "// Generated by tinvar codegen. Do not edit.\n// {$about}\n";
    }

    /** @param list<RouteTypes> $routes */
    private function renderModule(array $routes, bool $hasActions): string
    {
        $out = $this->preamble('Source of truth: the PHP Data classes, #[Page] and #[Layout] routes, and #[Action] methods.');

        $hasLayouts = array_filter($routes, static fn (RouteTypes $r): bool => $r->kind === RouteKind::Layout) !== [];
        $hasNamed = array_filter(
            $routes,
            static fn (RouteTypes $r): bool => $r->kind === RouteKind::Page && $r->name !== null,
        ) !== [];

        // React's own types by import: a module cannot reach the UMD global
        // `React` that a declaration file could.
        $imports = '';
        if ($hasLayouts) {
            $imports .= "import type { ReactNode } from 'react';\n";
        }
        if ($hasNamed) {
            $imports .= \sprintf("import { routes } from %s;\n", self::literal(self::ROUTE_RUNTIME, 'The route runtime'));
        }
        $out .= $imports === '' ? "\n" : "\n{$imports}\n";

        $sections = array_values($this->interfaces);
        $sections[] = $this->renderRoutes($routes);

        // The body ActionEndpoint answers with, not the PHP object: it typed
        // revalidatedRoutes, revalidatedTags and redirect, none of which the
        // body has. The tags travel in the x-invalidated-tags header and a
        // redirect is a 303 with no body, so neither is a field here.
        if ($hasActions) {
            $sections[] = "/** What an action's endpoint answers with. Its invalidated tags are in the x-invalidated-tags header; a redirect is a 303. */\n"
                . "export interface ActionResult {\n"
                . "  /** What the action handed back with ActionResult::ok() or withValue(), through the encoder. */\n"
                . "  value: unknown;\n"
                . "  flash: Record<string, unknown>;\n"
                . "  /** The routes it revalidated, by name. */\n"
                . "  revalidated: string[];\n"
                . "  /** A fresh RSC payload for each mounted page it re-rendered, by page key. */\n"
                . "  payloads: Record<string, string>;\n"
                . "}\n";
        }

        return $out . implode("\n", array_map(
            static fn (string $section): string => rtrim($section, "\n") . "\n",
            array_filter($sections, static fn (string $section): bool => $section !== ''),
        ));
    }

    /**
     * @param list<array{name: string, module: string, function: string, args: list<array{name: string, type: string, optional?: bool}>, returns: string}> $queries
     * @param list<array{module: string, function: string}> $actions
     */
    private function renderAmbient(array $queries, array $actions): string
    {
        $out = $this->preamble(
            "The @queries/* and @actions/* modules. Nothing here is imported or exported at the top level, so\n"
            . "// each `declare module` declares its module rather than augmenting one that does not exist.",
        );

        // One module per wire-name prefix: `racks.unitLoad` is unitLoad in
        // '@queries/racks'. Every query went into '@queries/racks' -- the
        // example app's name, written into the framework -- so any other
        // application's queries were typed under a module it does not have.
        /** @var array<string, list<string>> $modules module => its members, rendered */
        $modules = [];
        foreach ($queries as $query) {
            $where = "The query `{$query['name']}`";
            $args = implode(
                '; ',
                array_map(
                    static fn (array $a): string => self::key($a['name'], $where)
                        . (($a['optional'] ?? false) ? '?' : '') . ": {$a['type']}",
                    $query['args'],
                ),
            );
            $modules[$query['module']][] = \sprintf(
                "  /** Calls the PHP query `%s` over the request's socket. */\n  export function %s(args: { %s }): Promise<%s>;\n",
                self::comment($query['name']),
                $query['function'],
                $args,
                $query['returns'],
            );
        }
        ksort($modules);

        $byClass = [];
        foreach ($actions as $action) {
            $byClass[$action['module']][] = $action['function'];
        }

        foreach ([...$modules, ...$byClass] as $module => $members) {
            $aliases = $this->aliasesFor(implode('', $members), str_starts_with((string) $module, '@actions'));

            $out .= \sprintf("\ndeclare module %s {\n", self::literal((string) $module, "The module {$module}"));
            $out .= $aliases === '' ? '' : $aliases . "\n";
            $out .= implode('', $members);
            // Everything in an ambient module is exported unless the module
            // says what it exports; without this the aliases would be too.
            $out .= $aliases === '' ? '' : "  export {};\n";
            $out .= "}\n";
        }

        return $out;
    }

    /**
     * Aliases for the interfaces a declared module's members name, from the
     * module file -- the one place they are declared. Import types, because
     * an ambient module may not import by a relative path (TS2439), and
     * aliases so the signatures read as the module file's do.
     */
    private function aliasesFor(string $members, bool $action): string
    {
        $names = $action ? ['ActionResult'] : [];
        foreach ($this->types->registered() as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $members) === 1) {
                $names[] = $name;
            }
        }

        $names = array_unique($names);
        sort($names);

        $module = self::literal($this->module, 'The path of the module file');

        return implode('', array_map(
            static fn (string $name): string => "  type {$name} = import({$module}).{$name};\n",
            $names,
        ));
    }

    /**
     * PageProps, LayoutProps and a typed route() helper.
     *
     * The maps are generated from the #[Page] and #[Layout] attributes, so a
     * view name that does not exist, or a missing route parameter, is a type
     * error rather than a broken link found at runtime.
     *
     * @param list<RouteTypes> $routes
     */
    private function renderRoutes(array $routes): string
    {
        if ($routes === []) {
            return '';
        }

        $pages = array_values(array_filter($routes, static fn (RouteTypes $r): bool => $r->kind === RouteKind::Page));
        $layouts = array_values(array_filter($routes, static fn (RouteTypes $r): bool => $r->kind === RouteKind::Layout));

        $out = '';

        if ($pages !== []) {
            $out .= "export interface PageDataMap {\n";
            foreach ($pages as $page) {
                $out .= sprintf("  %s: %s;\n", self::literal($page->view, "The view {$page->view}"), $this->dataTypeOf($page));
            }
            $out .= "}\n\nexport interface PageParamsMap {\n";
            foreach ($pages as $page) {
                $out .= sprintf("  %s: %s;\n", self::literal($page->view, "The view {$page->view}"), self::paramsType($page->parameters));
            }
            $out .= "}\n\n";
            $out .= "export type PageProps<V extends keyof PageDataMap> = {\n"
                . "  data: PageDataMap[V];\n  params: PageParamsMap[V];\n};\n\n";
        }

        if ($layouts !== []) {
            $out .= "export interface LayoutDataMap {\n";
            foreach ($layouts as $layout) {
                $out .= sprintf(
                    "  %s: %s;\n",
                    self::literal($layout->layoutKey, "The layout {$layout->layoutKey}"),
                    $this->dataTypeOf($layout),
                );
            }
            $out .= "}\n\n";
            $out .= "export type LayoutProps<K extends keyof LayoutDataMap> = {\n"
                . "  data: LayoutDataMap[K];\n  children: ReactNode;\n};\n\n";
        }

        $named = array_values(array_filter($pages, static fn (RouteTypes $r): bool => $r->name !== null));
        if ($named !== []) {
            $out .= "export interface RouteMap {\n";
            foreach ($named as $route) {
                $out .= sprintf(
                    "  %s: %s;\n",
                    self::literal((string) $route->name, "The route {$route->name}"),
                    self::paramsType($route->parameters),
                );
            }
            $out .= "}\n\n";

            // A function, not a declaration of one. Declared only, it
            // type-checked and then failed the build: nothing defined it.
            $out .= "/** Builds a URL for a named route. A missing parameter is a type error. */\n"
                . "export const route = routes<RouteMap>({\n";
            foreach ($named as $route) {
                $out .= sprintf(
                    "  %s: [%s],\n",
                    self::literal((string) $route->name, "The route {$route->name}"),
                    implode(', ', array_map(
                        static fn (string $segment): string => self::literal($segment, "A segment of the route {$route->name}"),
                        self::segmentsOf($route),
                    )),
                );
            }
            $out .= "});\n";
        }

        return $out;
    }

    /**
     * A named route's path segments, which must place every one of its
     * parameters: a table that left one out would build URLs without it.
     *
     * @return list<string>
     */
    private static function segmentsOf(RouteTypes $route): array
    {
        foreach ($route->parameters as $parameter) {
            if (!\in_array('{' . $parameter . '}', $route->segments, true)) {
                throw new CodegenException(
                    "The route {$route->name} has a parameter `{$parameter}` its path segments do not place.",
                );
            }
        }

        return $route->segments;
    }

    /** @param list<string> $parameters */
    private static function paramsType(array $parameters): string
    {
        if ($parameters === []) {
            return 'Record<string, never>';
        }

        return '{ ' . implode('; ', array_map(
            static fn (string $p): string => self::key($p, "The route parameter {$p}") . ': string',
            $parameters,
        )) . ' }';
    }

    /**
     * A single-quoted string literal.
     *
     * Every name from an attribute that is written into the output as a
     * string goes through here. Interpolated as they were, a view, route or
     * module name with a quote in it closed the string and wrote declarations
     * of its own into every consumer's types. A line terminator cannot stand
     * in a string literal at all, U+2028 and U+2029 included.
     */
    private static function literal(string $value, string $where): string
    {
        $escaped = preg_replace_callback(
            '/[\\\\\'\x00-\x1f\x7f\x{2028}\x{2029}]/u',
            static fn (array $m): string => match ($m[0]) {
                '\\' => '\\\\',
                "'" => "\\'",
                default => \sprintf('\\u%04x', mb_ord($m[0], 'UTF-8')),
            },
            $value,
        );

        // A pattern with /u fails on a string that is not UTF-8, and such a
        // name could not be written into a source file faithfully anyway.
        if ($escaped === null) {
            throw new CodegenException("{$where} is not valid UTF-8.");
        }

        return "'{$escaped}'";
    }

    /**
     * Text placed inside a block comment: it cannot end the comment, and it
     * stays on the comment's line.
     */
    private static function comment(string $text): string
    {
        return str_replace(
            ['*/', "\r\n", "\r", "\n", "\u{2028}", "\u{2029}"],
            ['*\/', ' ', ' ', ' ', ' ', ' '],
            $text,
        );
    }

    /**
     * A name written as code -- a function or an interface -- or a refusal.
     * There is no escaping an identifier: one that is not exactly a name is
     * code, and a reserved word is not a name a function can have.
     */
    private static function identifier(string $name, string $where): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new CodegenException(
                "{$where} would be emitted as `{$name}`, which is not a TypeScript identifier. Rename it.",
            );
        }

        if (\in_array($name, self::RESERVED, true)) {
            throw new CodegenException(
                "{$where} would be emitted as `{$name}`, a reserved word in TypeScript. Rename it.",
            );
        }

        return $name;
    }

    /**
     * A property name: bare when it is an identifier, as PHP field and
     * parameter names are in practice, and quoted otherwise. A query's
     * argument names are attribute keys, which can hold anything.
     */
    private static function key(string $name, string $where): string
    {
        return preg_match(self::IDENTIFIER, $name) === 1 ? $name : self::literal($name, $where);
    }

    /**
     * A module name, `@queries/...` or `@actions/...`, made of path segments
     * and nothing else. It is quoted as well; this is what stops a query's
     * wire name from naming a module that is not one of ours.
     */
    private static function module(string $module, string $where): string
    {
        if (preg_match('#^@(?:queries|actions)(?:/[A-Za-z0-9_.-]+)*$#D', $module) !== 1) {
            throw new CodegenException("{$where} would be emitted in `{$module}`, which is not a module name. Rename it.");
        }

        return $module;
    }

    /** What a page or layout's loader returns, as a type: each class it may return, and null if it may. */
    private function dataTypeOf(RouteTypes $route): string
    {
        // A page or layout with no loader still has props; its data is null.
        if ($route->dataClasses === []) {
            return 'null';
        }

        $names = array_map($this->types->register(...), $route->dataClasses);
        if ($route->nullableData) {
            $names[] = 'null';
        }

        return implode(' | ', $names);
    }
}
