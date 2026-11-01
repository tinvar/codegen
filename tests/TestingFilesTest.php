<?php

declare(strict_types=1);

/**
 * The JavaScript half of the testing layer, as codegen writes it (ADR 0061):
 * a fake of each Data interface, and mocks of the `@queries/*` and
 * `@actions/*` modules built on @tinvar/react/testing.
 *
 * A fake's every value comes from the field's name and type -- nothing else
 * is read -- and the files compile, under the repository's strict settings,
 * against the module file they fake and the runtime they import.
 */

namespace Tinvar\Tests\Codegen\TestingFiles;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Rescue;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\CursorScroll;
use Tinvar\Data\Data;
use Tinvar\Data\Scroll;
use Tinvar\Support\Lazy;
use Tinvar\Tests\Codegen\Fixtures as F;

require_once __DIR__ . '/Fixtures/surface.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';

enum TfShade: string
{
    case Dark = 'dark';
    case Light = 'light';
}

enum TfNothing: string {}

final readonly class TfItem extends Data
{
    public function __construct(public string $id, public int $count) {}
}

final readonly class TfShelf extends Data
{
    /**
     * @param list<TfItem>          $items
     * @param array<string, int>    $totals
     * @param value-of<TfShade>     $shade
     * @param Lazy<int>             $seen
     * @param list<TfItem>          $latest
     * @param Scroll<TfItem>        $pages
     * @param Lazy<list<TfItem>>    $history
     * @param Lazy<TfItem>          $risky
     * @param Lazy<list<TfItem>>    $more
     * @param CursorScroll<TfItem>  $recent
     */
    public function __construct(
        public string $title,
        public float $weight,
        public bool $open,
        public ?string $note,
        public array $items,
        public array $totals,
        public TfItem $first,
        public ?TfItem $second,
        public string $shade,
        public mixed $extra,
        #[Once]
        public Lazy $seen,
        #[Merge(matchOn: 'id')]
        public array $latest,
        public Scroll $pages,
        #[Optional]
        public Lazy $history,
        #[Rescue]
        public Lazy $risky,
        #[Merge(append: 'data')]
        public TfPaged $paged,
        #[Optional, Merge(matchOn: 'id')]
        public Lazy $more,
        public CursorScroll $recent,
    ) {}
}

/** A page of a paginator's shape, merged into by its `data`. */
final readonly class TfPaged extends Data
{
    /** @param list<TfItem> $data */
    public function __construct(public array $data, public int $total) {}
}

final readonly class TfLoopA extends Data
{
    public function __construct(public TfLoopB $b) {}
}

final readonly class TfLoopB extends Data
{
    public function __construct(public TfLoopA $a) {}
}

/** A structure that refers to itself, and ends: its parent may be null. */
final readonly class TfNode extends Data
{
    /** @param list<TfNode> $children */
    public function __construct(public ?TfNode $parent, public array $children) {}
}

final readonly class TfEmpty extends Data
{
    /** @param value-of<TfNothing> $kind */
    public function __construct(public string $kind) {}
}

#[Query(name: 'shelves.find')]
final class FindShelf
{
    public function __invoke(string $shelf): TfShelf
    {
        throw new \LogicException('never called');
    }
}

#[Query(name: 'shelves.deep.count')]
final class CountShelves
{
    public function __invoke(?string $tag = null): int
    {
        return 0;
    }
}

#[Query(name: 'top')]
final class TopShelf
{
    /** @return list<TfItem> */
    public function __invoke(): array
    {
        return [];
    }
}

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

/** @return array<string, string> */
function testing(): array
{
    $actions = new ActionRegistry();
    $actions->register(F\SiteActions::class);

    return new TypeScriptGenerator()->files(
        [TfShelf::class, TfNode::class],
        [FindShelf::class, CountShelves::class, TopShelf::class],
        actions: $actions->all(),
    )['testing'];
}

it('makes each value from the field\'s name and type', function () {
    $fakes = testing()['fakes.ts'];

    expect($fakes)->toContain("export function fakeTfShelf(overrides: Overrides<TfShelf> = {}): TfShelf {\n")
        ->and($fakes)->toContain("      title: 'title',\n")
        ->and($fakes)->toContain("      weight: 0,\n")
        ->and($fakes)->toContain("      open: false,\n")
        ->and($fakes)->toContain("      note: null,\n")
        ->and($fakes)->toContain("      items: [],\n")
        ->and($fakes)->toContain("      totals: {},\n")
        ->and($fakes)->toContain("      first: fakeTfItem(),\n")
        ->and($fakes)->toContain("      second: null,\n")
        // An enum's first case, by value.
        ->and($fakes)->toContain("      shade: 'dark',\n")
        ->and($fakes)->toContain("      extra: null,\n")
        ->and($fakes)->toContain("      seen: { \$once: 'seen', ttl: null, tags: [], value: 0 },\n")
        ->and($fakes)->toContain("      latest: { \$merge: 'latest', mode: 'append', reset: false, matchOn: null, items: [] },\n")
        ->and($fakes)->toContain("      pages: { \$scroll: 'pages', reset: false, page: 1,")
        // The value side of `T | Unrequested` and `T | Rescued`.
        ->and($fakes)->toContain("      history: [],\n")
        ->and($fakes)->toContain("      risky: fakeTfItem(),\n")
        // Its own fake, the list under the path an empty merge field.
        ->and($fakes)->toContain("      paged: { ...fakeTfPaged(), data: { \$merge: 'paged.data', mode: 'append', reset: false, matchOn: null, items: [] } },\n")
        ->and($fakes)->toContain("      more: { \$merge: 'more', mode: 'append', reset: false, matchOn: null, items: [] },\n")
        ->and($fakes)->toContain("      recent: { \$scroll: 'recent', reset: false, cursor: null, previous: null, next: null, total: null,")
        ->and($fakes)->toContain("    },\n    overrides,\n  );\n")
        ->and($fakes)->toContain("import { withOverrides, type Overrides } from '@tinvar/react/testing';")
        ->and($fakes)->toContain("import type { ActionResult, TfItem, TfNode, TfPaged, TfShelf } from '../generated';")
        ->and($fakes)->toContain('export function fakeActionResult(');
});

it('mocks each query module where tinvarTesting() looks for it', function () {
    $files = testing();

    expect(array_keys($files))->toEqualCanonicalizing([
        'fakes.ts',
        'queries.ts',
        'queries/shelves.ts',
        'queries/shelves/deep.ts',
        'actions/SiteActions.ts',
    ])
        ->and($files['queries/shelves.ts'])->toContain(
            "export const find = mockQuery<{ shelf: string }, TfShelf>('shelves.find', () => fakeTfShelf());",
        )
        ->and($files['queries/shelves.ts'])->toContain("import type { TfShelf } from '../../generated';")
        ->and($files['queries/shelves.ts'])->toContain("import { fakeTfShelf } from '../fakes';")
        ->and($files['queries/shelves/deep.ts'])->toContain(
            "export const count = mockQuery<{ tag?: string | null }, number>('shelves.deep.count', () => 0);",
        )
        ->and($files['queries/shelves/deep.ts'])->not->toContain("generated'")
        ->and($files['queries.ts'])->toContain("export const top = mockQuery<{  }, TfItem[]>('top', () => []);")
        ->and($files['queries.ts'])->toContain("import type { TfItem } from '../generated';")
        ->and($files['actions/SiteActions.ts'])->toContain(
            "export const rename = mockAction<{ site: string; name: string; priority?: number | null }, ActionResult>('SiteActions.rename', fakeActionResult);",
        );
});

// ADR 0061: queryFunctions() keeps the testing layer out of a build by the
// runtime's specifier and marker. The mocks import it; the fakes import
// nothing else that would, so without it a fake reached a production page.
it('imports the testing runtime into every file, the fakes too, so no build can take one', function () {
    foreach (testing() as $path => $source) {
        expect($source)->toMatch("/^import (?:\\{ (?:mock(?:Query|Action)|withOverrides, type Overrides) \\} from )?'@tinvar\\/react\\/testing';$/m", $path);
    }
});

it('refuses Data classes that need each other with no way to stop', function () {
    expect(fn () => new TypeScriptGenerator()->files([TfLoopA::class]))
        ->toThrow(CodegenException::class, 'TfLoopA -> TfLoopB -> TfLoopA');
});

it('ends a structure that refers to itself at a field that may be null', function () {
    expect(testing()['fakes.ts'])->toContain("      parent: null,\n      children: [],\n");
});

it('refuses a field no value can fill', function () {
    expect(fn () => new TypeScriptGenerator()->files([TfEmpty::class]))
        ->toThrow(CodegenException::class, 'an enum with no cases');
});

it('reads no value from anywhere but the type', function () {
    $_ENV['TINVAR_FAKE_PROBE'] = 'a-secret-from-the-environment';

    try {
        $files = implode("\n", testing());
    } finally {
        unset($_ENV['TINVAR_FAKE_PROBE']);
    }

    // Every string a fake holds is its field's own name, or an enum's case.
    preg_match_all("/^      ([A-Za-z]+): '([^']*)',$/m", $files, $strings, PREG_SET_ORDER);
    $foreign = array_filter($strings, static fn (array $m): bool => $m[1] !== $m[2] && $m[2] !== TfShade::Dark->value);
    expect($files)->not->toContain('a-secret-from-the-environment')
        ->and(\count($strings))->toBeGreaterThan(2)
        ->and($foreign)->toBe([]);
});

/**
 * Compiles the testing files beside the module file, as an app's tsconfig
 * maps them for Vitest: the runtime's declarations for its specifier, and
 * the two prefixes to the mocks.
 *
 * @param array<string, string> $files
 * @return array{int, string}
 */
function compile(array $files): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-testing-tsc-' . bin2hex(random_bytes(6));
    mkdir($dir);

    $files['tsconfig.json'] = json_encode([
        'extends' => "{$root}/tsconfig.base.json",
        'compilerOptions' => [
            'noEmit' => true,
            'types' => [],
            'paths' => [
                'react' => ["{$root}/node_modules/@types/react"],
                '@tinvar/react/testing' => ["{$root}/packages/react/src/testing.d.ts"],
                TypeScriptGenerator::ACTION_RUNTIME => ["{$root}/packages/react/src/action.d.ts"],
                '@tinvar/react/*' => ["{$root}/packages/react/src/*.d.ts"],
                '@queries' => ['./testing/queries.ts'],
                '@queries/*' => ['./testing/queries/*'],
                '@actions/*' => ['./testing/actions/*'],
            ],
        ],
        'files' => array_keys($files),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    try {
        foreach ($files as $name => $contents) {
            if (!is_dir(\dirname("{$dir}/{$name}"))) {
                mkdir(\dirname("{$dir}/{$name}"), 0o700, true);
            }
            file_put_contents("{$dir}/{$name}", $contents);
        }

        $process = proc_open(
            [TSC, '-p', "{$dir}/tsconfig.json", '--pretty', 'false'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        \assert(\is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    } finally {
        $all = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($all as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

/** A test's use of each: overrides typed by the interface, mocks by the module they stand in for. */
const CONSUMER = <<<'TS'
    import { answer, calls } from '@tinvar/react/testing';
    import { find } from '@queries/shelves';
    import { count } from '@queries/shelves/deep';
    import { top } from '@queries';
    import { rename } from '@actions/SiteActions';
    import { fakeTfItem, fakeTfShelf } from './testing/fakes';
    import type { TfShelf } from './generated';

    export async function use(): Promise<number> {
      const shelf: TfShelf = fakeTfShelf({ title: 'R-14', first: fakeTfItem({ count: 3 }) });
      answer(find, shelf);
      answer(count, ({ tag }) => (tag === null ? 0 : 1));
      const found = await find({ shelf: 's-1' });
      const items = await top({});
      await rename({ site: 's-1', name: 'R-1', priority: null });

      // @ts-expect-error an override is held to the field's type
      fakeTfShelf({ title: 14 });
      // @ts-expect-error and to the fields there are
      fakeTfShelf({ titel: 'R-14' });
      // @ts-expect-error a mock is called as the query it stands in for
      await find({});

      return found.first.count + items.length + calls(find).length + calls(rename).length;
    }

    TS;

it('compiles, with each mock typed as the module it stands in for', function () {
    $actions = new ActionRegistry();
    $actions->register(F\SiteActions::class);
    $files = new TypeScriptGenerator()->files(
        [TfShelf::class, TfNode::class],
        [FindShelf::class, CountShelves::class, TopShelf::class],
        actions: $actions->all(),
    );

    $written = ['consumer.ts' => CONSUMER, 'generated.ts' => $files['module']];
    foreach ($files['testing'] as $path => $contents) {
        $written["testing/{$path}"] = $contents;
    }
    [$exit, $output] = compile($written);

    expect($output)->toBe('')
        ->and($exit)->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');
