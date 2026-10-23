<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Routing\RouteKind;
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
     * @param list<RouteTypes>         $routes
     * @param list<CompiledAction>     $actions
     */
    public function generate(
        array $dataClasses,
        array $queryClasses = [],
        array $routes = [],
        array $actions = [],
    ): string {
        $this->interfaces = [];
        $this->types = new TypeMapper();

        foreach ($dataClasses as $class) {
            $this->emitData($class);
        }

        // A route's loader return type is a Data class the components need.
        foreach ($routes as $route) {
            if ($route->dataClass !== null) {
                $this->emitData($route->dataClass);
            }
        }

        $queries = array_map($this->describeQuery(...), $queryClasses);

        // Emitting a Data class can pull in others it references; keep going
        // until the set closes.
        while (($pending = array_diff_key($this->types->registered(), $this->interfaces)) !== []) {
            foreach (array_keys($pending) as $class) {
                $this->emitData($class);
            }
        }

        return $this->render($queries, $routes, $actions);
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
     * @param list<array{name: string, function: string, args: list<array{name: string, type: string}>, returns: string}> $queries
     * @param list<RouteTypes>     $routes
     * @param list<CompiledAction> $actions
     */
    private function render(array $queries, array $routes = [], array $actions = []): string
    {
        $out = $this->header === '' ? '' : $this->header . "\n";
        $out .= "// Generated by tinvar codegen. Do not edit.\n";
        $out .= "// Source of truth: the PHP Data classes and #[Query] attributes.\n\n";

        foreach ($this->interfaces as $body) {
            $out .= $body . "\n";
        }

        // One module per wire-name prefix: `racks.unitLoad` is unitLoad in
        // '@queries/racks'. Every query went into '@queries/racks' -- the
        // example app's name, written into the framework -- so any other
        // application's queries were typed under a module it does not have.
        $modules = [];
        foreach ($queries as $query) {
            $modules[self::moduleFor($query['name'])][] = $query;
        }
        ksort($modules);

        foreach ($modules as $module => $members) {
            $out .= "declare module '{$module}' {\n";
            foreach ($members as $query) {
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

        $out .= $this->renderRoutes($routes);
        $out .= $this->renderActions($actions);

        return $out;
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

        $out = "\n";

        if ($pages !== []) {
            $out .= "export interface PageDataMap {\n";
            foreach ($pages as $page) {
                $out .= sprintf("  '%s': %s;\n", $page->view, $this->nameFor($page->dataClass));
            }
            $out .= "}\n\nexport interface PageParamsMap {\n";
            foreach ($pages as $page) {
                $out .= sprintf("  '%s': %s;\n", $page->view, self::paramsType($page->parameters));
            }
            $out .= "}\n\n";
            $out .= "export type PageProps<V extends keyof PageDataMap> = {\n"
                . "  data: PageDataMap[V];\n  params: PageParamsMap[V];\n};\n\n";
        }

        if ($layouts !== []) {
            $out .= "export interface LayoutDataMap {\n";
            foreach ($layouts as $layout) {
                $out .= sprintf("  '%s': %s;\n", $layout->layoutKey, $this->nameFor($layout->dataClass));
            }
            $out .= "}\n\n";
            $out .= "export type LayoutProps<K extends keyof LayoutDataMap> = {\n"
                . "  data: LayoutDataMap[K];\n  children: React.ReactNode;\n};\n\n";
        }

        $named = array_values(array_filter($pages, static fn (RouteTypes $r): bool => $r->name !== null));
        if ($named !== []) {
            $out .= "export interface RouteMap {\n";
            foreach ($named as $route) {
                $out .= sprintf("  '%s': %s;\n", $route->name, self::paramsType($route->parameters));
            }
            $out .= "}\n\n";
            $out .= "/** Builds a URL for a named route. A missing parameter is a type error. */\n"
                . "export declare function route<N extends keyof RouteMap>(name: N, params: RouteMap[N]): string;\n";
        }

        return $out;
    }

    /** @param list<CompiledAction> $actions */
    private function renderActions(array $actions): string
    {
        if ($actions === []) {
            return '';
        }

        /** @var array<string, list<CompiledAction>> $byClass */
        $byClass = [];
        foreach ($actions as $action) {
            $byClass[Str::shortName($action->class)][] = $action;
        }

        $out = "\nexport interface ActionResult {\n"
            . "  revalidatedRoutes: string[];\n  revalidatedTags: string[];\n"
            . "  flash: Record<string, unknown>;\n  redirect: string | null;\n}\n";

        foreach ($byClass as $short => $group) {
            $out .= sprintf("\ndeclare module '@actions/%s' {\n", $short);
            foreach ($group as $action) {
                $arguments = [];
                foreach ($action->models as $parameter => $_) {
                    $arguments[] = "{$parameter}: string";   // a model crosses as its id
                }
                foreach ($action->formRequests as $class) {
                    foreach (DataShape::fieldsOf($class) as $field) {
                        $type = $field->getType();
                        $arguments[] = sprintf(
                            '%s: %s',
                            $field->getName(),
                            $type === null ? 'unknown' : $this->types->map($type, null, $class, (new \ReflectionClass($class))->getNamespaceName()),
                        );
                    }
                }

                $out .= sprintf(
                    "  /** Posts to the PHP action `%s`. */\n  export function %s(args: { %s }): Promise<ActionResult>;\n",
                    $action->name,
                    $action->method,
                    implode('; ', $arguments),
                );
            }
            $out .= "}\n";
        }

        return $out;
    }

    /** @param list<string> $parameters */
    private static function paramsType(array $parameters): string
    {
        if ($parameters === []) {
            return 'Record<string, never>';
        }

        return '{ ' . implode('; ', array_map(
            static fn (string $p): string => "{$p}: string",
            $parameters,
        )) . ' }';
    }

    /** @param class-string|null $class */
    private function nameFor(?string $class): string
    {
        // A page or layout with no loader still has props; its data is null.
        return $class === null ? 'null' : $this->types->register($class);
    }
}
