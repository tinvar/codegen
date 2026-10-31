<?php

declare(strict_types=1);

/**
 * A list or map whose element is a union, or a nullable one, is mapped
 * part by part: `list<string|null>` is `(string | null)[]`. They were
 * read as one opaque name and refused.
 */

namespace Tinvar\Tests\Codegen\UnionElementType;

use Tinvar\Codegen\TypeMapper;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;

final readonly class UeItem extends Data
{
    public function __construct(public string $id) {}
}

final readonly class UeShapes extends Data
{
    /**
     * @param list<string|null>       $names
     * @param list<int|string>        $keys
     * @param array<string, int|null> $counts
     * @param ?list<int>              $maybe
     * @param list<UeItem|null>       $items
     * @param list<int>|null          $later
     */
    public function __construct(
        public array $names,
        public array $keys,
        public array $counts,
        public ?array $maybe,
        public array $items,
        public ?array $later,
    ) {}
}

it('maps a union or nullable element type in a docblock hint', function (string $hint, string $expected) {
    expect((new TypeMapper())->mapHint($hint, 'x'))->toBe($expected);
})->with([
    ['list<int|string>', '(number | string)[]'],
    ['list<string|null>', '(string | null)[]'],
    ['list<string | null>', '(string | null)[]'],
    ['array<string, int|null>', 'Record<string, number | null>'],
    ['?list<int>', 'number[] | null'],
    ['?int', 'number | null'],
    ['int[]|null', 'number[] | null'],
    ['int|string[]', 'number | string[]'],
    ['(int|string)[]', '(number | string)[]'],
    ['list<list<int|null>>', '(number | null)[][]'],
    ['list<?int>', '(number | null)[]'],
    ['array<string, list<int>|null>', 'Record<string, number[] | null>'],
    ['int|int', 'number'],
]);

it('maps a union or nullable element type on a Data field', function () {
    expect((new TypeScriptGenerator())->generate([UeShapes::class]))
        ->toContain('names: (string | null)[];')
        ->toContain('keys: (number | string)[];')
        ->toContain('counts: Record<string, number | null>;')
        ->toContain('maybe: number[] | null;')
        ->toContain('items: (UeItem | null)[];')
        ->toContain('later: number[] | null;');
});

final readonly class UeFlags extends Data
{
    /**
     * @param list<true>         $set
     * @param list<string|false> $names
     */
    public function __construct(
        public true $on,
        public string|false $label,
        public array $set,
        public array $names,
    ) {}
}

it('maps true and false to their literal types in a docblock, as on a native type', function () {
    $types = new TypeMapper();

    expect($types->mapHint('true', 'x'))->toBe('true')
        ->and($types->mapHint('false', 'x'))->toBe('false')
        ->and($types->mapHint('bool', 'x'))->toBe('boolean')
        ->and((new TypeScriptGenerator())->generate([UeFlags::class]))
        ->toContain('on: true;')
        ->toContain('label: string | false;')
        ->toContain('set: true[];')
        ->toContain('names: (string | false)[];');
});
