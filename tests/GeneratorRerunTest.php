<?php

declare(strict_types=1);

/**
 * A generator run twice describes the second run's classes alone. What the
 * first run's fields needed -- the OnceField, MergeField and Unrequested
 * imports -- carried over into the next, which then imported types it did
 * not use, or refused a Data class named like one.
 */

namespace Tinvar\Tests\Codegen\GeneratorRerun;

use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Support\Lazy;

final readonly class GrItem extends Data
{
    public function __construct(public string $id) {}
}

final readonly class GrPage extends Data
{
    /**
     * @param Lazy<int>          $total
     * @param list<GrItem>       $latest
     * @param Lazy<list<GrItem>> $history
     */
    public function __construct(
        #[Once]
        public Lazy $total,
        #[Merge(matchOn: 'id')]
        public array $latest,
        #[Optional]
        public Lazy $history,
    ) {}
}

final readonly class GrPlain extends Data
{
    public function __construct(public string $title) {}
}

/** Named like a type the module imports, which is only a clash when that type is used. */
final readonly class OnceField extends Data
{
    public function __construct(public string $title) {}
}

it('gives the same files on a second run as a fresh generator does', function () {
    $generator = new TypeScriptGenerator();
    $generator->files([GrPage::class]);

    expect($generator->files([GrPlain::class]))->toBe((new TypeScriptGenerator())->files([GrPlain::class]));
});

it('does not refuse a class named like a type only an earlier run used', function () {
    $generator = new TypeScriptGenerator();
    $generator->files([GrPage::class]);

    expect($generator->generate([OnceField::class]))->toContain('export interface OnceField {');
});
