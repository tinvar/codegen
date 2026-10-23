<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\ActionResultType;

/**
 * The generated ActionResult against what the action endpoint really sends.
 *
 * It described the PHP object -- revalidatedRoutes, revalidatedTags, flash,
 * redirect -- while the endpoint answers with a body of value, flash,
 * revalidated and payloads, puts the tags in a header, and sends a redirect
 * as a 303 with no body at all. A stub typed by it promised fields that
 * never arrive.
 */

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../../framework/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../framework/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../../framework/tests/Actions/Fixtures/actions.php';

/** The fields of the generated ActionResult, in order. */
function typed(ActionRegistry $registry): array
{
    $ts = (new TypeScriptGenerator())->generate([], [], [], $registry->all());
    preg_match('/export interface ActionResult \{\n(.+?)\n\}/s', $ts, $m);
    preg_match_all('/^\s*(\w+):/m', $m[1] ?? '', $fields);

    return $fields[1];
}

it('describes the body the action endpoint answers with', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new ActionRegistry();
    $registry->register(A\RackActions::class);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

    $response = (new ActionEndpoint($runner))->handle(
        Request::post('/_action/RackActions.moveUnit'),
        json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12], JSON_THROW_ON_ERROR),
    );
    $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->status)->toBe(200)
        ->and(typed($registry))->toBe(array_keys($body))
        // Carried beside the body, so not fields of it.
        ->and($response->header('x-invalidated-tags'))->not->toBeNull();
});

it('types each field as the endpoint fills it', function () {
    $registry = new ActionRegistry();
    $registry->register(A\RackActions::class);

    expect((new TypeScriptGenerator())->generate([], [], [], $registry->all()))
        ->toContain("  value: unknown;\n")
        ->toContain("  flash: Record<string, unknown>;\n")
        ->toContain("  revalidated: string[];\n")
        ->toContain("  payloads: Record<string, string>;\n")
        ->not->toContain('redirect:')
        ->not->toContain('revalidatedTags:');
});
