<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\LoaderDataType;

/**
 * A page's data type, from its loader's return type.
 *
 * Only a single named class was read: a `?AData` loader was typed AData --
 * its null was dropped -- and an `AData|BData` loader was typed null, as if
 * it had no loader at all.
 */

use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Attributes\Page;

final readonly class AData extends Data
{
    public function __construct(public string $a) {}
}

final readonly class BData extends Data
{
    public function __construct(public string $b) {}
}

#[Page('/maybe', view: 'maybe')]
final class NullableLoader
{
    public function __invoke(): ?AData
    {
        return null;
    }
}

#[Page('/either', view: 'either')]
final class UnionLoader
{
    public function __invoke(): AData|BData
    {
        return new AData('a');
    }
}

#[Page('/either-or-none', view: 'either-or-none')]
final class NullableUnionLoader
{
    public function __invoke(): AData|BData|null
    {
        return null;
    }
}

#[Page('/plain', view: 'plain')]
final class PlainLoader
{
    public function __invoke(): AData
    {
        return new AData('a');
    }
}

function pageData(): string
{
    $registry = new RouteRegistry();
    $registry->registerAll([NullableLoader::class, UnionLoader::class, NullableUnionLoader::class, PlainLoader::class]);

    $ts = (new TypeScriptGenerator())->generate([], [], array_map(RouteTypes::of(...), $registry->pages()));
    preg_match('/export interface PageDataMap \{\n(.+?)\n\}/s', $ts, $m);

    return $m[1] ?? '';
}

it('types a nullable loader as its data or null', function () {
    expect(pageData())->toContain("  'maybe': AData | null;");
});

it('types a loader returning a union as the union', function () {
    expect(pageData())
        ->toContain("  'either': AData | BData;")
        ->toContain("  'either-or-none': AData | BData | null;");
});

it('still types a loader of one class as that class', function () {
    expect(pageData())->toContain("  'plain': AData;");
});

it('emits the interface of every class in the union', function () {
    $registry = new RouteRegistry();
    $registry->registerAll([UnionLoader::class]);

    expect((new TypeScriptGenerator())->generate([], [], array_map(RouteTypes::of(...), $registry->pages())))
        ->toContain('export interface AData {')
        ->toContain('export interface BData {');
});
