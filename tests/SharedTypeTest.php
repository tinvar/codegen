<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\SharedType;

/**
 * Shared keys typed for the views that read them (ADR 0060): the module
 * file augments `@tinvar/react/server`'s SharedDataMap, so `shared('key')`
 * is the Data its #[Shared] method returns -- a ProvidesData read as what
 * its toData() returns -- and a key nobody shares is a type error. And a
 * ProvidesData field is typed as what it provides.
 */

use Tinvar\Codegen\SharedTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\SharedRegistry;
use Tinvar\Tests\Data\Fixtures\Shared\PageWithGreetingData;
use Tinvar\Tests\Data\Fixtures\Shared\SiteShared;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Query/Fixtures/queries.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Data/Fixtures/shared.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Data/Fixtures/shared-once.php';

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

function module(): string
{
    return (new TypeScriptGenerator())->files(
        [PageWithGreetingData::class],
        shared: SharedTypes::of(new SharedRegistry([SiteShared::class])),
    )['module'];
}

/** @return array{int, string} tsc's exit code and output over the module and $consumer */
function compile(string $consumer): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-shared-tsc-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $files = [
        'generated.ts' => module(),
        'consumer.ts' => $consumer,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => [
                'noEmit' => true,
                'skipLibCheck' => false,
                'types' => [],
                'paths' => [TypeScriptGenerator::SHARED_RUNTIME => ["{$root}/packages/react/src/server.d.ts"]],
            ],
            'files' => ['generated.ts', 'consumer.ts'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ];

    try {
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }
        $process = proc_open([TSC, '-p', "{$dir}/tsconfig.json", '--pretty', 'false'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
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

it('declares each key in an augmentation of the runtime\'s SharedDataMap, by name', function () {
    expect(module())->toContain(
        "declare module '@tinvar/react/server' {\n  interface SharedDataMap {\n"
        . "    boom: GreetingData;\n"
        . "    greeting: GreetingData;\n"
        . "    leak: LooseData;\n"
        . "    nobody: GreetingData | null;\n"
        . "    rack: RackSummaryData;\n"
        . "    where: GreetingData;\n"
        . "    writes: GreetingData;\n"
        . "  }\n}\n",
    );
});

it('types a ProvidesData field as what it provides', function () {
    expect(module())->toContain("export interface PageWithGreetingData {\n  greeting: GreetingData;\n}");
});

it('types a once key as a once field of its value, and imports the type for it', function () {
    $module = (new TypeScriptGenerator())->files(
        [],
        shared: SharedTypes::of(new SharedRegistry([\Tinvar\Tests\Data\Fixtures\Shared\OnceShared::class])),
    )['module'];

    expect($module)->toContain("import type { OnceField } from '@tinvar/react/once';")
        ->toContain("    motd: OnceField<GreetingData>;\n")
        ->toContain("    pinned: OnceField<GreetingData | null>;\n")
        ->toContain("    plain: GreetingData;\n");
});

it('emits no augmentation when nothing is shared', function () {
    expect((new TypeScriptGenerator())->files([PageWithGreetingData::class])['module'])->not->toContain('declare module');
});

it('types shared() for a view, and refuses a key nobody shares', function () {
    [$exit, $output] = compile(<<<'TS'
        import { shared } from '@tinvar/react/server';
        import type { GreetingData, RackSummaryData } from './generated';

        export async function read(): Promise<string> {
          const greeting: GreetingData = await shared('greeting');
          const rack: RackSummaryData = await shared('rack');
          const nobody: GreetingData | null = await shared('nobody');
          // @ts-expect-error a key that may be null is not typed as never null
          const sure: GreetingData = await shared('nobody');
          // @ts-expect-error a key nobody shares
          await shared('missing');

          return `${greeting.text} ${rack.units} ${nobody?.text ?? ''} ${sure.text}`;
        }

        TS);

    expect($output)->toBe('')
        ->and($exit)->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');
