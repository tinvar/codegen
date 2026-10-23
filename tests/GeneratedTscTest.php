<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\GeneratedTsc;

/**
 * The generated files, compiled by tsc as an app compiles them.
 *
 * String assertions said the output contained `declare module
 * '@queries/racks'`; tsc said that, in a file with top-level exports, it was
 * an augmentation of a module that did not exist (TS2664), and every import
 * of a query failed to resolve (TS2307). And route() type-checked while
 * nothing defined it. Only the compiler can say the output works, so the
 * compiler is asked, under the repository's own strict settings.
 */

use Tinvar\Actions\ActionRegistry;
use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Codegen\Fixtures as F;

require_once __DIR__ . '/Fixtures/surface.php';
require_once __DIR__ . '/../../framework/tests/Orm/Fixtures/models.php';

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

/**
 * Writes the files beside a tsconfig that extends the repository's, runs
 * tsc over them, and returns its exit code and output.
 *
 * @param array<string, string> $files name => contents
 * @return array{int, string}
 */
function compile(array $files): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-tsc-' . bin2hex(random_bytes(6));
    mkdir($dir);

    $files['tsconfig.json'] = json_encode([
        'extends' => "{$root}/tsconfig.base.json",
        'compilerOptions' => [
            'noEmit' => true,
            // The base skips declaration files; the ambient one is generated
            // output and must be checked like the rest.
            'skipLibCheck' => false,
            'types' => [],
            'paths' => [
                'react' => ["{$root}/node_modules/@types/react"],
                TypeScriptGenerator::ROUTE_RUNTIME => ["{$root}/packages/react/src/route.d.ts"],
            ],
        ],
        'files' => array_keys($files),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    try {
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }

        $process = proc_open(
            [TSC, '-p', "{$dir}/tsconfig.json", '--pretty', 'false'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        \assert(\is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    } finally {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

/** @return array{module: string, ambient: string} */
function generated(): array
{
    $routes = new RouteRegistry();
    $routes->registerAll([F\SitesLayout::class, F\RackPage::class, F\AboutPage::class, F\RawPage::class]);

    $actions = new ActionRegistry();
    $actions->register(F\SiteActions::class);

    return (new TypeScriptGenerator())->files(
        [F\CgPage::class],
        [F\FindSite::class, F\SearchSites::class, F\Ping::class],
        array_map(RouteTypes::of(...), [...$routes->layouts(), ...$routes->pages()]),
        $actions->all(),
    );
}

/**
 * An app's use of every kind of output. Each `@ts-expect-error` must find
 * an error, so a type that quietly became `any` fails the compile too.
 */
const CONSUMER = <<<'TS'
    import { find } from '@queries/sites';
    import { ping, searchSites } from '@queries';
    import { rename } from '@actions/SiteActions';
    import { route, type ActionResult, type CgSite, type LayoutProps, type PageProps } from './generated';

    export async function use(): Promise<string[]> {
      const site: CgSite = await find({ site: 's-1' });
      const found: CgSite = await searchSites({ term: 'r', limit: 5, untyped: null });
      const up: boolean = await ping({});
      const result: ActionResult = await rename({ site: 's-1', name: 'R-1', priority: null });

      // @ts-expect-error a route parameter left out is a type error
      route('sites.rack', { site: 's-1' });
      // @ts-expect-error so is a query argument
      await find({});

      return [site.id, found.id, String(up), typeof result, route('sites.rack', { site: 's-1', rack: 'r-1' })];
    }

    export type RackPage = PageProps<'sites/rack'>['data'];
    export type Children = LayoutProps<'sites'>['children'];

    TS;

it('compiles, with every query, action and route an app imports resolving', function () {
    $files = generated();

    [$exit, $output] = compile([
        'consumer.ts' => CONSUMER,
        'generated.ts' => $files['module'],
        'modules.d.ts' => $files['ambient'],
    ]);

    expect($output)->toBe('')
        ->and($exit)->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');

it('keeps the query and action modules out of the module file, where they would be augmentations', function () {
    $files = generated();

    expect($files['module'])->not->toContain('declare module')
        ->and($files['ambient'])->not->toMatch('/^(?:import|export)\b/m')
        ->and($files['ambient'])->toContain(
            "declare module '@queries/sites' {\n  type CgSite = import('./generated').CgSite;\n",
        );
});

// An ambient module exports everything in it unless it says what it
// exports, so the aliases would have been importable from '@queries/sites'.
it('keeps the type aliases of a declared module to itself', function () {
    [$exit, $output] = compile([
        'consumer.ts' => "import type { CgSite } from '@queries/sites';\nexport type T = CgSite;\n",
        'generated.ts' => generated()['module'],
        'modules.d.ts' => generated()['ambient'],
    ]);

    expect($output)->toContain('TS2459')
        ->and($exit)->not->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');

it('takes the path of the module file the ambient one names', function () {
    $files = (new TypeScriptGenerator(module: '../types/app'))->files([], [F\FindSite::class]);

    expect($files['ambient'])->toContain("type CgSite = import('../types/app').CgSite;");
});
