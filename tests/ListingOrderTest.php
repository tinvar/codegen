<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\ListingOrder;

/**
 * The module codegen writes does not depend on how the caller listed the
 * Data classes.
 *
 * The generator finds every Data class a route, a query or an action
 * reaches by itself; a list names only those nothing reaches, such as an
 * email's. Its interfaces were written in the order the classes were met,
 * so listing one a route already reached -- or not, or in another order --
 * moved it, and `tinvar codegen --check` read the move as drift.
 */

use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Codegen\Fixtures as F;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/surface.php';

/** @param list<class-string<\Tinvar\Data\Data>> $listed */
function moduleWith(array $listed): string
{
    $routes = new RouteRegistry();
    $routes->registerAll([F\SitesLayout::class, F\RackPage::class]);

    return new TypeScriptGenerator()->files(
        $listed,
        [F\Ping::class, F\SiteStats::class],
        array_map(RouteTypes::of(...), [...$routes->layouts(), ...$routes->pages()]),
    )['module'];
}

it('writes the same module whether the Data classes a route reaches are listed or not', function () {
    $found = moduleWith([]);

    expect($found)->toContain('export interface CgPage')
        ->and($found)->toContain('export interface CgSite')
        ->and(moduleWith([F\CgSite::class]))->toBe($found)
        ->and(moduleWith([F\CgPage::class, F\CgSite::class]))->toBe($found)
        ->and(moduleWith([F\CgSite::class, F\CgPage::class]))->toBe($found);
});

it('writes each interface in the order of its name', function () {
    $module = moduleWith([F\CgSite::class, F\CgPage::class]);

    expect(strpos($module, 'export interface CgPage'))->toBeLessThan((int) strpos($module, 'export interface CgSite'));
});
