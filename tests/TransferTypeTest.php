<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\TransferType;

/**
 * The #[Export] and #[Import] classes typed for the client (ADR 0070): an
 * ExportMap of each export's arguments and formats, an ImportMap of each
 * import's arguments and columns, and a `transfer` built on the runtime --
 * compiled by tsc, so a format an export does not write is a type error.
 */

use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Tests\Transfers\Fixtures\StockImport;
use Tinvar\Tests\Transfers\Fixtures\WidgetsExport;
use Tinvar\Transfers\TransferRegistry;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/framework') . '/tests/Transfers/Fixtures/app.php';

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

function module(): string
{
    $registry = new TransferRegistry();
    $registry->registerAll([WidgetsExport::class, StockImport::class]);

    return (new TypeScriptGenerator())->files([], transfers: $registry->all())['module'];
}

/** @return array{int, string} tsc's exit code and output over the module and $consumer */
function compile(string $consumer): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-transfer-tsc-' . bin2hex(random_bytes(6));
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
                'paths' => [TypeScriptGenerator::TRANSFER_RUNTIME => ["{$root}/packages/react/src/transfer.d.ts"]],
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

it('describes each export and import by name: its arguments, formats and columns', function () {
    expect(module())
        ->toContain("import { transfers } from '@tinvar/react/transfer';")
        ->toContain("export interface ExportMap {\n  'widgets': { args: { minStock?: number | null }; formats: 'csv' | 'jsonl' };\n}")
        ->toContain("export interface ImportMap {\n  'stock': { args: Record<string, never>; columns: 'widget' | 'stock' };\n}")
        ->toContain('export const transfer = transfers<ExportMap, ImportMap>();');
});

it('emits nothing for an application with none', function () {
    expect((new TypeScriptGenerator())->files([])['module'])->not->toContain('transfer');
});

it('compiles, and refuses a format or a name the PHP does not declare', function () {
    [$ok, $output] = compile(<<<'TS'
        import { transfer } from './generated';

        export async function start(file: File): Promise<void> {
          await transfer.export('widgets', { format: 'jsonl', args: { minStock: 3 } });
          await transfer.import('stock', file);
        }
        TS);
    expect($ok)->toBe(0, $output);

    [$bad, $errors] = compile(<<<'TS'
        import { transfer } from './generated';

        export async function start(): Promise<void> {
          await transfer.export('widgets', { format: 'xlsx' });
          await transfer.export('secrets');
        }
        TS);
    expect($bad)->not->toBe(0)
        ->and($errors)->toContain("'\"xlsx\"' is not assignable")
        ->and($errors)->toContain("'\"secrets\"' is not assignable");
});
