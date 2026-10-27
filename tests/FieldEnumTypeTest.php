<?php

declare(strict_types=1);

/**
 * Sort and filter field enums in TypeScript (ADR 0057): a #[FieldsOf] enum,
 * or any string-backed enum a Data field is typed with as `value-of<...>`,
 * is emitted as the union of its values and a constant listing them, so
 * the client offers the same closed set the server reads a request into.
 */

namespace Tinvar\Tests\Codegen\FieldEnumType;

use Tinvar\Attributes\FieldsOf;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Model;

#[Table('fe_crates')]
final class Crate extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
    #[Column] public int $weightKg;
    #[Column] public string $secret;
}

#[MapsFrom(Crate::class)]
final readonly class CrateCardData extends Data
{
    public function __construct(public string $id, public string $label, public int $weightKg) {}
}

#[FieldsOf(CrateCardData::class)]
enum CrateSort: string
{
    case Label = 'label';
    case Weight = 'weightKg';
}

/** Names a column no card shows: refused, as `tinvar check` refuses it. */
#[FieldsOf(CrateCardData::class)]
enum LeakySort: string
{
    case Secret = 'secret';
}

enum Shade: string
{
    case Light = 'light';
    case Dark = "it's dark";
}

enum Level: int
{
    case Low = 1;
}

final readonly class CratesPageData extends Data
{
    /**
     * @param list<CrateCardData> $crates
     * @param value-of<CrateSort> $sort
     * @param value-of<Shade>|null $shade
     */
    public function __construct(public array $crates, public string $sort, public ?string $shade) {}
}

final readonly class LeakyPageData extends Data
{
    /** @param value-of<LeakySort> $sort */
    public function __construct(public string $sort) {}
}

final readonly class LevelPageData extends Data
{
    /** @param value-of<Level> $level */
    public function __construct(public string $level) {}
}

it('types a value-of<> field as the enum, and emits each enum as a union and a list', function () {
    $module = (new TypeScriptGenerator())->generate([CratesPageData::class]);

    expect($module)->toContain("  sort: CrateSort;\n  shade: Shade | null;\n")
        ->and($module)->toContain(
            "/** The fields of CrateCardData a request may name, as Tinvar\\Tests\\Codegen\\FieldEnumType\\CrateSort reads them (#[FieldsOf]). */\n"
            . "export type CrateSort = 'label' | 'weightKg';\n"
            . "export const CrateSort: readonly CrateSort[] = ['label', 'weightKg'];\n",
        )
        ->and($module)->toContain("export type Shade = 'light' | 'it\\'s dark';\n");
});

it('emits a listed #[FieldsOf] enum no field names, and refuses one listed without it', function () {
    $module = (new TypeScriptGenerator())->generate([CrateCardData::class], fieldEnums: [CrateSort::class]);

    expect($module)->toContain("export type CrateSort = 'label' | 'weightKg';")
        ->and(fn () => (new TypeScriptGenerator())->generate([CrateCardData::class], fieldEnums: [Shade::class]))
        ->toThrow(CodegenException::class, 'has no #[FieldsOf]');
});

it('refuses a #[FieldsOf] enum naming a field its Data class does not show, and an enum not backed by strings', function () {
    expect(fn () => (new TypeScriptGenerator())->generate([LeakyPageData::class]))
        ->toThrow(CodegenException::class, "names 'secret', which is not a field of")
        ->and(fn () => (new TypeScriptGenerator())->generate([LevelPageData::class]))
        ->toThrow(CodegenException::class, "only a string-backed enum's values are emitted");
});

it('compiles, and a value the server would refuse does not', function () {
    $root = (string) realpath(__DIR__ . '/../../..');
    $dir = sys_get_temp_dir() . '/tinvar-fe-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $files = [
        'generated.ts' => (new TypeScriptGenerator())->generate([CratesPageData::class]),
        'use.ts' => <<<'TS'
            import { CrateSort, type CratesPageData } from './generated';

            export function offered(data: CratesPageData): CrateSort[] {
              const every: readonly CrateSort[] = CrateSort;
              const chosen: CrateSort = data.sort;
              // @ts-expect-error a field the server does not take
              const hidden: CrateSort = 'secret';
              return [...every, chosen, hidden];
            }
            TS,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => ['noEmit' => true, 'types' => []],
            'files' => ['generated.ts', 'use.ts'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ];

    try {
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }
        exec(escapeshellarg("{$root}/node_modules/.bin/tsc") . ' -p ' . escapeshellarg("{$dir}/tsconfig.json") . ' --pretty false 2>&1', $output, $status);
    } finally {
        array_map(unlink(...), glob("{$dir}/*") ?: []);
        rmdir($dir);
    }

    expect($status)->toBe(0, implode("\n", $output));
});
