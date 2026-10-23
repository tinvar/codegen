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
     * @param class-string|null $dataClass the loader's return type, if it has one
     */
    public function __construct(
        public RouteKind $kind,
        public string $view,
        public ?string $name,
        public string $layoutKey,
        public array $parameters,
        public ?string $dataClass,
    ) {}

    public static function of(CompiledRoute $route): self
    {
        return new self(
            kind: $route->kind,
            view: $route->view,
            name: $route->name,
            layoutKey: $route->layoutKey,
            parameters: $route->parameters,
            dataClass: self::returnTypeOf($route->class),
        );
    }

    /** @param class-string $class @return class-string|null */
    private static function returnTypeOf(string $class): ?string
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasMethod('__invoke')) {
            return null;   // "Loaders are optional."
        }

        $type = $reflection->getMethod('__invoke')->getReturnType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string */
        return $type->getName();
    }
}
