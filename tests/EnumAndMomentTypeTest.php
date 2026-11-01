<?php

declare(strict_types=1);

/**
 * A Data field declared as a backed enum or an immutable moment, typed as
 * what the encoder sends for it: the enum's values as a union under its
 * name, and the moment as the ISO 8601 string it crosses as. Only the
 * field's own declared type: an enum or a moment in a list is refused here
 * as the encoder refuses it.
 */

namespace Tinvar\Tests\Codegen\EnumAndMomentType;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Codegen\CodegenException;
use Tinvar\Validation\FormRequest;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;

enum Stage: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
}

enum Urgency: int
{
    case Low = 1;
    case High = 2;
}

enum Shape
{
    case Round;
}

final readonly class ParcelData extends Data
{
    public function __construct(
        public Stage $stage,
        public ?Urgency $urgency,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $shippedAt,
        public \DateTimeImmutable $checkedAt,
    ) {}
}

final readonly class StageRequest extends FormRequest
{
    public function __construct(public Stage $stage, public ?string $note = null) {}
}

final class ParcelActions
{
    #[Action(name: 'parcels.stage'), PublicAccess]
    public function stage(StageRequest $request): ActionResult
    {
        return ActionResult::ok();
    }
}

final readonly class StagesData extends Data
{
    /** @param list<Stage> $stages */
    public function __construct(public array $stages) {}
}

final readonly class ShapeData extends Data
{
    public function __construct(public Shape $shape) {}
}

it('types an enum field as the union of its values, and a moment field as a string', function () {
    $module = (new TypeScriptGenerator())->generate([ParcelData::class]);

    expect($module)->toContain("  stage: Stage;\n  urgency: Urgency | null;\n  placedAt: string;\n  shippedAt: string | null;\n  checkedAt: string;\n")
        ->and($module)->toContain("export type Stage = 'placed' | 'shipped';\nexport const Stage: readonly Stage[] = ['placed', 'shipped'];\n")
        ->and($module)->toContain("export type Urgency = 1 | 2;\nexport const Urgency: readonly Urgency[] = [1, 2];\n");
});

// The validator reads a backed enum field from its value, and refuses any
// other: so an action's argument is the same union the server takes.
it('types an action\'s enum field as the union of the values the validator takes', function () {
    $registry = new ActionRegistry();
    $registry->register(ParcelActions::class);

    $files = (new TypeScriptGenerator())->files([], [], [], $registry->all());

    expect(implode("\n", $files['actions']))->toContain('stage: Stage; note?: string | null')->toContain('import type { ActionResult, Stage } from')
        ->and($files['module'])->toContain("export type Stage = 'placed' | 'shipped';");
});

it('refuses an enum in a list, which the encoder refuses, and a pure enum, which has no value to send', function () {
    expect(fn () => (new TypeScriptGenerator())->generate([StagesData::class]))
        ->toThrow(CodegenException::class, "Cannot map 'Stage'")
        ->and(fn () => (new TypeScriptGenerator())->generate([ShapeData::class]))
        ->toThrow(CodegenException::class, 'is a pure enum, which has no value to send');
});

it('compiles, and a value outside the enum does not', function () {
    $root = (string) realpath(__DIR__ . '/../../..');
    $dir = sys_get_temp_dir() . '/tinvar-em-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $files = [
        'generated.ts' => (new TypeScriptGenerator())->generate([ParcelData::class]),
        'use.ts' => <<<'TS'
            import { Urgency, type ParcelData, type Stage } from './generated';

            export function read(data: ParcelData): [Stage, Urgency | null, Date] {
              // @ts-expect-error a stage the server has no case for
              const lost: Stage = 'lost';
              const every: readonly Urgency[] = Urgency;
              return [lost, data.urgency ?? every[0] ?? null, new Date(data.placedAt)];
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
