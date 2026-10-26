<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\QueryMap;

/**
 * Which query each `@queries/*` function calls, written beside the types.
 *
 * The renderer build reads it to put a page's calls through these functions
 * on its route's query allowlist (tinvar-spec.md, "Renderer hardening"). It
 * cannot read the query back from the function: `racks.UnitLoad` is typed
 * as `unitLoad()`, and so would `racks.unitLoad` be.
 */

use Tinvar\Attributes\Query;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Tests\Codegen\Fixtures as F;

require_once __DIR__ . '/../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/surface.php';

#[Query(name: 'racks.UnitLoad')]
final class UpperCaseUnitLoad
{
    public function __invoke(): string
    {
        return '';
    }
}

#[Query(name: 'racks.unitLoad')]
final class LowerCaseUnitLoad
{
    public function __invoke(): string
    {
        return '';
    }
}

it('names the query each function calls, by module', function () {
    $files = new TypeScriptGenerator()->files([], [F\FindSite::class, F\Ping::class, F\SiteStats::class, UpperCaseUnitLoad::class]);

    expect(json_decode($files['queries'], true, 8, JSON_THROW_ON_ERROR))->toBe([
        'format' => 1,
        'modules' => [
            '@queries' => ['ping' => 'ping'],
            '@queries/racks' => ['unitLoad' => 'racks.UnitLoad'],
            '@queries/sites' => ['find' => 'sites.find', 'stats' => 'sites.stats'],
        ],
    ]);
});

it('writes an empty map, as an object, for an app with no queries', function () {
    expect(new TypeScriptGenerator()->files([])['queries'])->toBe("{\n    \"format\": 1,\n    \"modules\": {}\n}\n");
});

// One function in the types, two queries behind it: the map could name only
// one, and a page calling the other would be refused, or allowed the wrong one.
it('refuses two queries emitted as the same function', function () {
    expect(fn () => new TypeScriptGenerator()->files([], [UpperCaseUnitLoad::class, LowerCaseUnitLoad::class]))
        ->toThrow(CodegenException::class, 'would both be emitted as unitLoad() in @queries/racks');
});
