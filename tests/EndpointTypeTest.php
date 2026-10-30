<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\EndpointType;

/**
 * The #[Get], #[Post] and #[Endpoint] routes typed for the client (ADR
 * 0067): an EndpointMap of each one's path parameters, its form requests'
 * fields as its input and what it answers with, and an `endpoint` built on
 * the runtime from a table of its methods and segments -- compiled by tsc,
 * so a missing parameter or a wrong field is a type error.
 */

use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Tests\Codegen\Fixtures\Endpoints\RackEndpoints;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/endpoints.php';

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

function module(): string
{
    $registry = new EndpointRegistry();
    $registry->register(RackEndpoints::class);

    return (new TypeScriptGenerator())->files([], endpoints: $registry->all())['module'];
}

/** @return array{int, string} tsc's exit code and output over the module and $consumer */
function compile(string $consumer): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-endpoint-tsc-' . bin2hex(random_bytes(6));
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
                'lib' => ['es2022', 'dom'],
                'paths' => [TypeScriptGenerator::ENDPOINT_RUNTIME => ["{$root}/packages/react/src/endpoint.d.ts"]],
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

it('describes each endpoint by name, its model fields as ids', function () {
    expect(module())
        ->toContain("import { endpoints } from '@tinvar/react/endpoint';")
        ->toContain("export interface EndpointMap {\n"
            . "  'racks.label': { params: { rack: string }; input: Record<string, never>; returns: Response };\n"
            . "  'racks.move': { params: { rack: string }; input: { site: string }; returns: RackStockData };\n"
            . "  'racks.resize': { params: { rack: string }; input: { heightU: number; note: string | null }; returns: undefined };\n"
            . "  'racks.stock': { params: { rack: string }; input: Record<string, never>; returns: RackStockData };\n"
            . "}\n")
        ->toContain("  'racks.move': { methods: ['PUT', 'PATCH'], segments: ['racks', '{rack}', 'site'], returns: 'data' },\n");
});

it('emits nothing for an application with no endpoints', function () {
    expect((new TypeScriptGenerator())->files([])['module'])->not->toContain('endpoint');
});

it('types endpoint.url() and endpoint.call(), and refuses what PHP would', function () {
    [$exit, $output] = compile(<<<'TS'
        import { endpoint, type RackStockData } from './generated';

        export async function use(rack: string): Promise<string> {
          const href: string = endpoint.url('racks.label', { rack });
          const stock: RackStockData = await endpoint.call('racks.stock', { params: { rack } });
          const pdf: Response = await endpoint.call('racks.label', { params: { rack } });
          await endpoint.call('racks.resize', { params: { rack }, input: { heightU: 24, note: null } });
          await endpoint.call('racks.move', { params: { rack }, input: { site: 's1' }, method: 'PATCH' });
          // @ts-expect-error a missing path parameter
          endpoint.url('racks.label', {});
          // @ts-expect-error a field the form request does not have
          await endpoint.call('racks.resize', { params: { rack }, input: { height: 24 } });
          // @ts-expect-error an endpoint nobody declared
          await endpoint.call('racks.delete', { params: { rack } });

          return `${href} ${stock.free} ${pdf.status}`;
        }

        TS);

    expect($output)->toBe('')
        ->and($exit)->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');
