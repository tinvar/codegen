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
 *
 * And the abilities each query's own #[Authorize] checks, which the lint
 * holds a query called inside a can() branch to ("Defaults and build
 * rules").
 */

use Tinvar\Attributes\Authorize;
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

#[Query(name: 'racks.audit'), Authorize('view', 'rack')]
final class RackAudit
{
    #[Authorize('audit', 'rack'), Authorize('view', 'rack')]
    public function __invoke(F\CgSite $rack): string
    {
        return '';
    }
}

it('names the query each function calls, by module, and the abilities each checks', function () {
    $files = new TypeScriptGenerator()->files([], [F\FindSite::class, F\Ping::class, F\SiteStats::class, UpperCaseUnitLoad::class, RackAudit::class]);

    expect(json_decode($files['queries'], true, 8, JSON_THROW_ON_ERROR))->toBe([
        'format' => 1,
        'modules' => [
            '@queries' => ['ping' => 'ping'],
            '@queries/racks' => ['audit' => 'racks.audit', 'unitLoad' => 'racks.UnitLoad'],
            '@queries/sites' => ['find' => 'sites.find', 'stats' => 'sites.stats'],
        ],
        // The class's checks and the method's, each ability once; a query
        // declaring none is listed with none, not left out.
        'authorize' => [
            'ping' => [],
            'racks.UnitLoad' => [],
            'racks.audit' => ['audit', 'view'],
            'sites.find' => [],
            'sites.stats' => [],
        ],
    ]);
});

it('writes empty maps, as objects, for an app with no queries', function () {
    expect(new TypeScriptGenerator()->files([])['queries'])
        ->toBe("{\n    \"format\": 1,\n    \"modules\": {},\n    \"authorize\": {}\n}\n");
});

// One function in the types, two queries behind it: the map could name only
// one, and a page calling the other would be refused, or allowed the wrong one.
it('refuses two queries emitted as the same function', function () {
    expect(fn () => new TypeScriptGenerator()->files([], [UpperCaseUnitLoad::class, LowerCaseUnitLoad::class]))
        ->toThrow(CodegenException::class, 'would both be emitted as unitLoad() in @queries/racks');
});
