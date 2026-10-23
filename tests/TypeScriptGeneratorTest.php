<?php

declare(strict_types=1);

use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;

final readonly class GenUnit extends Data
{
    public function __construct(
        public string $id,
        public int $position,
        public float $watts,
        public bool $online,
    ) {}
}

final readonly class GenRack extends Data
{
    /**
     * @param list<GenUnit> $units
     * @param array<string, int> $counts
     */
    public function __construct(
        public string $name,
        public array $units,
        public array $counts,
        public ?GenUnit $primary,
    ) {}
}

final readonly class GenLazy extends Data
{
    /** @var Lazy<list<GenUnit>> */
    public Lazy $units;

    public function __construct(Lazy $units)
    {
        $this->units = $units;
    }
}

final readonly class GenUntypedArray extends Data
{
    public function __construct(public array $things) {}
}

final class GenNotData
{
    public string $secret = 'no';
}

final readonly class GenLeaky extends Data
{
    public function __construct(public GenNotData $model) {}
}

it('maps PHP scalars to TypeScript', function () {
    $ts = (new TypeScriptGenerator())->generate([GenUnit::class]);

    expect($ts)->toContain('export interface GenUnit {')
        ->toContain('id: string;')
        ->toContain('position: number;')
        ->toContain('watts: number;')
        ->toContain('online: boolean;');
});

it('reads element types from docblocks', function () {
    $ts = (new TypeScriptGenerator())->generate([GenRack::class]);

    expect($ts)->toContain('units: GenUnit[];')
        ->toContain('counts: Record<string, number>;');
});

it('maps a nullable field to a union with null', function () {
    expect((new TypeScriptGenerator())->generate([GenRack::class]))
        ->toContain('primary: GenUnit | null;');
});

// The encoder resolves a Lazy and sends its value (Encoder::walk); typed as
// a Promise, a component awaited a list, or passed it to use(), and got the
// list itself.
it('maps Lazy<T> to T, the value the encoder sends', function () {
    expect((new TypeScriptGenerator())->generate([GenLazy::class]))
        ->toContain('units: GenUnit[];')
        ->and((new Encoder())->encode(new GenLazy(Lazy::of(fn () => [new GenUnit('u1', 1, 1.5, true)]))))
        ->toBe(['units' => [['id' => 'u1', 'position' => 1, 'watts' => 1.5, 'online' => true]]]);
});

it('follows references, so one root pulls in the classes it needs', function () {
    $ts = (new TypeScriptGenerator())->generate([GenRack::class]);

    expect($ts)->toContain('export interface GenRack {')
        ->and($ts)->toContain('export interface GenUnit {');
});

it('refuses a field that is not a Data object', function () {
    expect(fn () => (new TypeScriptGenerator())->generate([GenLeaky::class]))
        ->toThrow(CodegenException::class, 'GenNotData');
});

it('refuses an array with no element type instead of emitting unknown[]', function () {
    expect(fn () => (new TypeScriptGenerator())->generate([GenUntypedArray::class]))
        ->toThrow(CodegenException::class, 'element type');
});

it('refuses a class that is not a Data object at all', function () {
    expect(fn () => (new TypeScriptGenerator())->generate([GenNotData::class]))
        ->toThrow(CodegenException::class, 'not a Data object');
});

// The reason DataShape exists. If these ever disagreed, the generated types
// would describe a payload the encoder does not produce.
it('emits exactly the fields the encoder writes', function () {
    $rack = new GenRack(
        name: 'R-14',
        units: [new GenUnit('u1', 1, 120.5, true)],
        counts: ['total' => 1],
        primary: null,
    );

    $encodedKeys = array_keys((new Encoder())->encode($rack));

    $ts = (new TypeScriptGenerator())->generate([GenRack::class]);
    preg_match('/export interface GenRack \{(.+?)\}/s', $ts, $m);
    preg_match_all('/^\s*(\w+):/m', $m[1], $fields);

    expect($fields[1])->toBe($encodedKeys);
});
