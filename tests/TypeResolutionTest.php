<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\TypeResolution;

/**
 * Types a Data field names that the mapper could not read: PHP's own true
 * and false, short names imported with `use`, and a docblock tag followed
 * by a description.
 */

use Tinvar\Codegen\DocBlock;
use Tinvar\Codegen\Imports;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Tests\Codegen\Resolution as R;

require_once __DIR__ . '/Fixtures/resolution.php';

// `string|false` is how half of PHP's own functions answer, and PHP 8.2
// allows true and false on their own. Both were refused: "Cannot map 'false'".
it('maps true and false', function () {
    expect((new TypeScriptGenerator())->generate([R\LiteralData::class]))
        ->toContain('  label: string | false;')
        ->toContain('  always: true;')
        ->toContain('  never: false | null;');
});

// Only registered classes and the class's own namespace were searched, so a
// short name imported from another namespace was refused unless that class
// happened to have been emitted first.
it('resolves a short name through the use statements of the class', function () {
    $ts = (new TypeScriptGenerator())->generate([R\TaggedData::class]);

    expect($ts)
        ->toContain('  tags: TagData[];')
        ->toContain('  remarks: NoteData[];')
        ->toContain('  labels: LabelData[];')
        ->toContain('export interface TagData {')
        ->toContain('export interface NoteData {')
        ->toContain('export interface LabelData {');
});

it('reads the class imports of the namespace a class is declared in, and only those', function () {
    expect(Imports::of(new \ReflectionClass(R\TaggedData::class)))->toBe([
        'data' => 'Tinvar\Data\Data',
        'tagdata' => 'Tinvar\Tests\Codegen\Resolution\Elsewhere\TagData',
        'remark' => 'Tinvar\Tests\Codegen\Resolution\Elsewhere\NoteData',
        'sub' => 'Tinvar\Tests\Codegen\Resolved\Sub',
        'groupedtag' => 'Tinvar\Tests\Codegen\Resolution\Elsewhere\TagData',
        'notedata' => 'Tinvar\Tests\Codegen\Resolution\Elsewhere\NoteData',
    ])
        ->and(Imports::of(new \ReflectionClass(R\Elsewhere\TagData::class)))->toBe([]);
});

// A description after the type was read as part of it.
it('stops a docblock type where its description starts', function () {
    expect((new TypeScriptGenerator())->generate([R\DescribedData::class]))
        ->toContain('  tags: TagData[];')
        ->toContain('  counts: Record<string, number>;')
        ->toContain('  maybe: TagData[] | null;');
});

it('reads a tag type up to its description, keeping what is inside brackets', function () {
    expect(DocBlock::typeFor('/** @var array<string, list<int>> counts, by name */'))->toBe('array<string, list<int>>')
        ->and(DocBlock::typeFor("/**\n * @var list<int>|null the ids\n */"))->toBe('list<int>|null')
        ->and(DocBlock::typeFor('/** @var list<int> */'))->toBe('list<int>')
        ->and(DocBlock::returnType('/** @return list<int> the rows */'))->toBe('list<int>');
});
