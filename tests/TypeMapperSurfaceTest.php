<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\TypeMapperSurface;

use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;

final readonly class TmShapes extends Data
{
    /**
     * @param int[][]                        $grid
     * @param list<array<string, int>>       $rows
     * @param array<int, array<string, int>> $byId
     */
    public function __construct(
        public int|string|null $either,
        public array $grid,
        public array $rows,
        public array $byId,
    ) {}
}

final readonly class TmLeaf extends Data
{
    public function __construct(public string $id) {}
}

it('maps nested array shapes and a union that includes null', function () {
    expect((new TypeScriptGenerator())->generate([TmShapes::class]))
        ->toContain('either: string | number | null;')
        ->toContain('grid: number[][];')
        ->toContain('rows: Record<string, number>[];')
        ->toContain('byId: Record<number, Record<string, number>>;');
});

it('emits a Data class named more than once a single time', function () {
    $ts = (new TypeScriptGenerator())->generate([TmLeaf::class, TmLeaf::class]);

    expect(substr_count($ts, 'export interface TmLeaf {'))->toBe(1);
});
