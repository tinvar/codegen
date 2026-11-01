<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteKind;

/**
 * Describes one #[Page] or #[Layout] for the emitter.
 *
 * Kept separate from the compiled route so codegen depends on the shape it
 * needs rather than on the router's internals.
 */
final readonly class RouteTypes
{
    /**
     * @param list<string> $parameters
     * @param list<class-string> $dataClasses the classes the loader's return
     *                                        type names: one, or each of a
     *                                        union. None without a loader.
     * @param list<string> $segments the path, `racks` or `{rack}` each, which
     *                               the generated route() builds URLs from
     * @param bool $nullableData whether the loader may return null
     * @param string|null $host the #[Domain] pattern it is served on, null for the primary
     *                          host; route() builds its absolute URL with it (ADR 0149)
     * @param list<string> $hostParameters the parameters the host takes, which lead $parameters
     */
    public function __construct(
        public RouteKind $kind,
        public string $view,
        public ?string $name,
        public string $layoutKey,
        public array $parameters,
        public array $dataClasses,
        public array $segments = [],
        public bool $nullableData = false,
        public ?string $host = null,
        public array $hostParameters = [],
    ) {}

    public static function of(CompiledRoute $route): self
    {
        [$classes, $nullable] = self::returnTypeOf($route->class);

        return new self(
            kind: $route->kind,
            view: $route->view,
            name: $route->name,
            layoutKey: $route->layoutKey,
            parameters: $route->parameters,
            dataClasses: $classes,
            segments: $route->segments,
            nullableData: $nullable,
            host: $route->domain(),
            hostParameters: $route->host->parameters ?? [],
        );
    }

    /**
     * The classes a loader may return, and whether it may return null.
     *
     * Every member of the type is read. Only a single named class was: a
     * `?AData` loader lost its null, and an `AData|BData` one was typed as
     * having no data at all.
     *
     * @param class-string $class
     * @return array{list<class-string>, bool}
     */
    private static function returnTypeOf(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasMethod('__invoke')) {
            return [[], false];   // "Loaders are optional."
        }

        $type = $reflection->getMethod('__invoke')->getReturnType();
        $members = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $classes = [];
        foreach ($members as $member) {
            if ($member instanceof \ReflectionNamedType && !$member->isBuiltin() && class_exists($member->getName())) {
                $classes[] = $member->getName();
            }
        }

        // A loader with no class to describe has no data type, null or not.
        return [$classes, $classes !== [] && $type->allowsNull()];
    }
}
