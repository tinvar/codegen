<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Lazy;
use Tinvar\Orm\Model;
use Tinvar\Routing\RouteKind;
use Tinvar\Support\Str;

/**
 * Emits TypeScript for the Data objects a build uses, plus typed `@queries/*`
 * stubs and `@actions/*` references (tinvar-spec.md, "Data boundary and
 * TypeScript codegen").
 *
 * Fields come from DataShape, the same source the encoder walks, so the
 * generated interface always describes the payload that is actually produced.
 *
 * A build writes its files side by side; see files(). The first two were one, and
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

    /** Where the generated action references get theirs. */
    public const ACTION_RUNTIME = '@tinvar/react/action';

    /** Where the Rescued type a #[Rescue] field may be sent as is declared. */
    public const RESCUE_RUNTIME = '@tinvar/react/rescue';

    /** Where the OnceField type a #[Once] field is sent as is declared. */
    public const ONCE_RUNTIME = '@tinvar/react/once';

    /** Where the MergeField and ScrollField types are declared. */
    public const MERGE_RUNTIME = '@tinvar/react/merge';

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

    /** Whether any emitted field is #[Rescue], so the module needs the Rescued type. */
    private bool $rescues = false;

    /** Whether any emitted field is #[Once], so the module needs the OnceField type. */
    private bool $onces = false;

    /** Whether any emitted field is #[Merge], so the module needs the MergeField type. */
    private bool $merges = false;

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
     * The files a build writes.
     *
     *   module   A TypeScript module (`generated.ts`): the Data interfaces,
     *            PageProps and LayoutProps, ActionResult, and route() -- a
     *            real function, built on ROUTE_RUNTIME, so importing it
     *            resolves at build time as well as type-checking.
     *   ambient  A declaration file (`modules.d.ts`) with no top-level import
     *            or export, holding `declare module '@queries/...'`. Being a
     *            script is what makes each one a declaration. Each module
     *            takes the types it names from the module file, by the path
     *            given to the constructor.
     *   actions  One real module per class with #[Action] methods, by the
     *            class's short name, for an `actions/` directory beside the
     *            other two that the app maps `@actions/*` to. Each exports
     *            one reference per action, built on ACTION_RUNTIME.
     *
     * Actions are modules of their own, not ambient declarations as queries
     * are: a query is called by a server component through the render's
     * socket, but an action reference is a VALUE -- handed to <Form action>,
     * called from a client component, given `.optimistic()` -- and the
     * declarations it once had described functions nothing defined, so the
     * first import of one failed the build.
     *
     * @param list<class-string<Data>> $dataClasses
     * @param list<class-string>       $queryClasses
     * @param list<RouteTypes>         $routes
     * @param list<CompiledAction>     $actions
     * @return array{module: string, ambient: string, actions: array<string, string>}
     */
    public function files(
        array $dataClasses,
        array $queryClasses = [],
        array $routes = [],
        array $actions = [],
    ): array {
        $this->interfaces = [];
        $this->rescues = false;
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

        // The same for the marker type the module imports.
        if ($this->rescues && \in_array('Rescued', $this->types->registered(), true)) {
            throw new CodegenException(
                'A Data class named Rescued would clash with the type a #[Rescue] field is sent as. Rename it.',
            );
        }
        $imported = [
            'OnceField' => $this->onces,
            'MergeField' => $this->merges,
            'ScrollField' => $this->types->mappedScroll(),
        ];
        foreach ($imported as $type => $used) {
            if ($used && \in_array($type, $this->types->registered(), true)) {
                throw new CodegenException("A Data class named {$type} would clash with the type of the same name the module imports. Rename it.");
            }
        }

        return [
            'module' => $this->renderModule($routes, $actions !== []),
            'ambient' => $this->renderAmbient($queries),
            'actions' => $this->renderActions($described),
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
        $rescued = DataShape::rescued($class);
        $once = DataShape::once($class);
        $merged = DataShape::merged($class);
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

            // What the encoder sends when the field's resolver throws, so a
            // component cannot read the value without deciding what to show
            // instead. Only a Lazy is ever resolved by the encoder, so only a
            // Lazy can be rescued; on anything else the mark would promise a
            // marker that is never sent.
            if (isset($rescued[$field])) {
                if (!$type instanceof \ReflectionNamedType || $type->getName() !== Lazy::class) {
                    throw new CodegenException(
                        "{$where} is marked #[Rescue], which only a Lazy field can be: its value is "
                        . 'produced before encoding, so there is nothing left to rescue.',
                    );
                }
                $mapped .= ' | Rescued';
                $this->rescues = true;
            }

            // Its items, with what the browser merges them by -- what the
            // encoder sends for a #[Merge] field. DataShape has already
            // refused one that is not a list.
            if (isset($merged[$field])) {
                if (!str_ends_with($mapped, '[]')) {
                    throw new CodegenException("{$where} is #[Merge], so its docblock must say what it is a list of.");
                }
                $mapped = sprintf('MergeField<%s>', substr($mapped, 0, -2));
                $this->merges = true;
            }

            // Its value, or -- in a response to a client that holds it -- its
            // key alone, so a component cannot read the value without going
            // through <Once>, which renders from what the browser kept.
            if (isset($once[$field])) {
                $mapped = sprintf('OnceField<%s>', $mapped);
                $this->onces = true;
            }

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
     * @return array{file: string, class: string, declaration: string}
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

        // Checked as a module path first, for the message, and then as an
        // identifier: it names a FILE now, and a name that is a path segment
        // but not an identifier -- `..` -- would be written somewhere else.
        $module = self::module('@actions/' . Str::shortName($action->class), "The actions of {$action->class}");
        $file = self::identifier(substr($module, \strlen('@actions/')), "The actions of {$action->class}");

        return [
            'file' => $file,
            'class' => $action->class,
            'declaration' => \sprintf(
                "/** Posts to the PHP action `%s`. */\nexport const %s = action<{ %s }, ActionResult>(%s);\n",
                self::comment($action->name),
                self::identifier($action->method, "{$action->class}::{$action->method}()"),
                implode('; ', $arguments),
                self::literal($action->name, $where),
            ),
        ];
    }

    /**
     * One module per class: its action references, and the types they name
     * from the module file.
     *
     * @param list<array{file: string, class: string, declaration: string}> $actions
     * @return array<string, string> file name, without extension => source
     */
    private function renderActions(array $actions): array
    {
        /** @var array<string, array{class: string, declarations: list<string>}> $byFile */
        $byFile = [];
        foreach ($actions as $action) {
            $byFile[$action['file']]['class'] = $action['class'];
            $byFile[$action['file']]['declarations'][] = $action['declaration'];
        }
        ksort($byFile);

        $files = [];
        foreach ($byFile as $file => ['class' => $class, 'declarations' => $declarations]) {
            $members = implode('', $declarations);
            $types = $this->typesNamedIn($members, ['ActionResult']);

            $files[$file] = $this->preamble(self::comment("The actions of {$class}: call one, or give it to <Form action>."))
                . \sprintf("\nimport { action } from %s;\n", self::literal(self::ACTION_RUNTIME, 'The action runtime'))
                . \sprintf(
                    "import type { %s } from %s;\n\n",
                    implode(', ', $types),
                    self::literal(self::fromActionsDirectory($this->module), 'The path of the module file'),
                )
                . implode("\n", $declarations);
        }

        return $files;
    }

    /**
     * The module file's path as an action module, one directory down, sees
     * it. A bare specifier -- a package -- is the same from anywhere.
     */
    private static function fromActionsDirectory(string $module): string
    {
        return match (true) {
            str_starts_with($module, './') => '../' . substr($module, 2),
            str_starts_with($module, '../') => '../' . $module,
            default => $module,
        };
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
        // From the package, not declared here: isRescued() narrows by it, and
        // two declarations of one shape are a drift waiting to happen.
        if ($this->rescues) {
            $imports .= \sprintf("import type { Rescued } from %s;\n", self::literal(self::RESCUE_RUNTIME, 'The rescue runtime'));
        }
        if ($this->onces) {
            $imports .= \sprintf("import type { OnceField } from %s;\n", self::literal(self::ONCE_RUNTIME, 'The once runtime'));
        }
        $merging = array_values(array_filter([
            $this->merges ? 'MergeField' : null,
            $this->types->mappedScroll() ? 'ScrollField' : null,
        ]));
        if ($merging !== []) {
            $imports .= \sprintf(
                "import type { %s } from %s;\n",
                implode(', ', $merging),
                self::literal(self::MERGE_RUNTIME, 'The merge runtime'),
            );
        }
        $out .= $imports === '' ? "\n" : "\n{$imports}\n";

        $sections = array_values($this->interfaces);
        $sections[] = $this->renderRoutes($routes);

        // The body ActionEndpoint answers with, not the PHP object: it typed
        // revalidatedRoutes, revalidatedTags and redirect, none of which the
        // body has. The tags travel in the x-invalidated-tags header and a
        // redirect is a 303 with no body, so neither is a field here.
        if ($hasActions) {
            $sections[] = "/** What an action's endpoint answers with. Its invalidated tags are in the x-invalidated-tags header; a redirect is a 303 (409 + X-Redirect to the client router). */\n"
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
     */
    private function renderAmbient(array $queries): string
    {
        $out = $this->preamble(
            "The @queries/* modules. Nothing here is imported or exported at the top level, so\n"
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

        foreach ($modules as $module => $members) {
            $aliases = $this->aliasesFor(implode('', $members));

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
    private function aliasesFor(string $members): string
    {
        $module = self::literal($this->module, 'The path of the module file');

        return implode('', array_map(
            static fn (string $name): string => "  type {$name} = import({$module}).{$name};\n",
            $this->typesNamedIn($members),
        ));
    }

    /**
     * The module file's types that rendered members name, sorted, plus any
     * they always need.
     *
     * @param list<string> $always
     * @return list<string>
     */
    private function typesNamedIn(string $members, array $always = []): array
    {
        $names = $always;
        foreach ($this->types->registered() as $name) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $members) === 1) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
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
