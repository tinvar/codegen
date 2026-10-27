<?php

declare(strict_types=1);

namespace Tinvar\Codegen;

use Tinvar\Data\Data;
use Tinvar\Data\SharedRegistry;

/**
 * One shared key for the emitter: the Data classes its value may be, and
 * whether it may be null -- a ProvidesData already read as what its
 * toData() returns. As RouteTypes is to a route, so codegen depends on the
 * shape it needs rather than on the registry.
 */
final readonly class SharedTypes
{
    /** @param list<class-string<Data>> $dataClasses */
    public function __construct(
        public string $key,
        public array $dataClasses,
        public bool $nullable,
        // #[Shared(once: true)]: sent as a once field, and typed as one (ADR 0065).
        public bool $once = false,
    ) {}

    /** @return list<self> every key the registry has, by name */
    public static function of(SharedRegistry $registry): array
    {
        $keys = $registry->keys();
        sort($keys);

        return array_map(static function (string $key) use ($registry): self {
            [$classes, $nullable] = $registry->typesOf($key);

            return new self($key, $classes, $nullable, $registry->onceOf($key) !== null);
        }, $keys);
    }
}
