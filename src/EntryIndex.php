<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Endpoints\CompiledEndpoint;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteKind;
use Tinvar\Support\Str;

/**
 * `entries.json`: every entry point codegen typed, with the PHP that answers
 * it -- the class, the method, the file -- and what the views reach it by
 * (ADR 0156).
 *
 * The facts an editor needs to go from a component to the PHP behind it and
 * back, and to list the routes, without parsing PHP: a page's view, path,
 * host and panel; a query's `@queries/*` module and function; an action's
 * `@actions/*` module and export; an endpoint's methods and path. Written
 * beside the types, so it is as current as they are and `codegen --check`
 * holds it to the PHP too.
 *
 * A file is relative to the directory the index is written in, so the index
 * reads the same on every machine and in every checkout. No line numbers: a
 * line moves with every edit above it, and the index would drift with each
 * one; an editor finds the method in the file named.
 */
final readonly class EntryIndex
{
    public const int FORMAT = 1;

    /**
     * @param string $out the directory the index is written in, which each file is relative to
     * @param PanelRegistry|null $panels the application's panels, for an entry point in one by its namespace alone
     */
    public function __construct(
        private string $out,
        private ?PanelRegistry $panels = null,
    ) {}

    /**
     * @param list<CompiledRoute> $layouts
     * @param list<CompiledRoute> $pages
     * @param list<class-string> $queries
     * @param list<CompiledAction> $actions
     * @param list<CompiledEndpoint> $endpoints
     */
    public function render(array $layouts, array $pages, array $queries, array $actions, array $endpoints): string
    {
        $entries = [];
        foreach ([...$layouts, ...$pages] as $route) {
            $entries[] = [
                'kind' => $route->kind === RouteKind::Layout ? 'layout' : 'page',
                'name' => $route->name,
                'class' => $route->class,
                'method' => $route->loader,
                'file' => $this->fileOf($route->class),
                'view' => $route->view,
                'path' => $route->template,
                'host' => $route->domain(),
                'panel' => $route->kind === RouteKind::Layout
                    ? $this->panelOf($route->panels, $route->class)
                    : $this->pagePanel($route, $layouts),
            ];
        }
        foreach ($queries as $class) {
            $declaration = new \ReflectionClass($class)->getAttributes(QueryAttribute::class)[0] ?? null;
            if ($declaration === null) {
                continue;
            }
            $name = $declaration->newInstance()->name ?? Str::queryName($class);
            $method = QueryAttribute::entryMethodOf($class);
            $entries[] = [
                'kind' => 'query',
                'name' => $name,
                'class' => $class,
                'method' => $method,
                'file' => $this->fileOf($class),
                'module' => TypeScriptGenerator::moduleFor($name),
                'export' => TypeScriptGenerator::functionName($name),
                'host' => Domain::declaredOn($class, $method)?->pattern,
                'panel' => $this->panelOf(Panel::declaredOn($class, $method), $class),
            ];
        }
        foreach ($actions as $action) {
            $entries[] = [
                'kind' => 'action',
                'name' => $action->name,
                'class' => $action->class,
                'method' => $action->method,
                'file' => $this->fileOf($action->class),
                'module' => '@actions/' . Str::shortName($action->class),
                'export' => $action->method,
                'host' => $action->host?->pattern,
                'panel' => $this->panelOf($action->panels, $action->class),
            ];
        }
        foreach ($endpoints as $endpoint) {
            $entries[] = [
                'kind' => 'endpoint',
                'name' => $endpoint->name,
                'class' => $endpoint->class,
                'method' => $endpoint->method,
                'file' => $this->fileOf($endpoint->class),
                'methods' => $endpoint->methods,
                'path' => $endpoint->template,
                'host' => $endpoint->host?->pattern,
                'panel' => $this->panelOf($endpoint->panels, $endpoint->class),
            ];
        }

        return json_encode(
            ['format' => self::FORMAT, 'entries' => $entries],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * A page's panel as the request has it: declared on a layout around it
     * or on the page, the outermost first, else its namespace's.
     *
     * @param list<CompiledRoute> $layouts
     */
    private function pagePanel(CompiledRoute $page, array $layouts): ?string
    {
        $declared = [];
        foreach ($layouts as $layout) {
            if ($layout->wraps($page)) {
                $declared = [...$declared, ...$layout->panels];
            }
        }

        return $this->panelOf([...$declared, ...$page->panels], $page->class);
    }

    /**
     * @param list<Panel> $declared
     * @param class-string $class
     */
    private function panelOf(array $declared, string $class): ?string
    {
        return $declared === [] ? $this->panels?->panelOf($class) : $declared[0]->name;
    }

    /**
     * The file a class is declared in, relative to the index's directory;
     * null for a class with no file (one declared in eval'd code).
     *
     * @param class-string $class
     */
    private function fileOf(string $class): ?string
    {
        $file = new \ReflectionClass($class)->getFileName();
        if ($file === false) {
            return null;
        }

        return self::relative(self::normalized($this->out), self::normalized($file));
    }

    /** $path made absolute, its `.` and `..` resolved, without touching the disk for a part that does not exist yet. */
    private static function normalized(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return str_replace('\\', '/', $real);
        }
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
            $path = str_replace('\\', '/', (string) getcwd()) . '/' . $path;
        }
        // The deepest part that exists, resolved, and the rest as written.
        $parent = \dirname($path);
        if ($parent !== $path && realpath($parent) === false) {
            return self::normalized($parent) . '/' . basename($path);
        }
        $realParent = realpath($parent);

        return ($realParent === false ? $parent : str_replace('\\', '/', $realParent)) . '/' . basename($path);
    }

    /** $to as a path from the directory $from, `/`-separated. */
    private static function relative(string $from, string $to): string
    {
        $fromParts = array_values(array_filter(explode('/', $from), static fn (string $part): bool => $part !== ''));
        $toParts = array_values(array_filter(explode('/', $to), static fn (string $part): bool => $part !== ''));
        $common = 0;
        while ($common < \count($fromParts) && $common < \count($toParts) && $fromParts[$common] === $toParts[$common]) {
            $common++;
        }

        return implode('/', [...array_fill(0, \count($fromParts) - $common, '..'), ...\array_slice($toParts, $common)]);
    }
}
