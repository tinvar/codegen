<?php

declare(strict_types=1);

/**
 * The types of #[Once], #[Merge] and Scroll fields: `OnceField<T>`,
 * `MergeField<T>` and `ScrollField<T>`, because that is what the encoder
 * sends (ADR 0020) -- a once field may be its key alone, and a merged list
 * crosses with what the browser merges it by. A type of `T` would have a
 * component read a value that is not there.
 */

namespace Tinvar\Tests\Codegen\OnceMergeType;

use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Rescue;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Data\PayloadContext;
use Tinvar\Data\Scroll;

final readonly class OmItem extends Data
{
    public function __construct(public string $id, public string $title) {}
}

final readonly class OmPage extends Data
{
    /**
     * @param Lazy<int>          $total
     * @param Lazy<list<OmItem>> $countries
     * @param list<OmItem>       $latest
     * @param Scroll<OmItem>     $items
     * @param Lazy<int>          $risky
     */
    public function __construct(
        #[Once(until: '1d')]
        public Lazy $total,
        #[Once(key: 'countries')]
        public Lazy $countries,
        #[Merge(matchOn: 'id')]
        public array $latest,
        public Scroll $items,
        #[Once, Rescue]
        public Lazy $risky,
    ) {}
}

final readonly class OmPlain extends Data
{
    public function __construct(public string $title) {}
}

final readonly class OnceField extends Data
{
    /** @param Lazy<int> $n */
    public function __construct(#[Once] public Lazy $n) {}
}

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

it('types each field as what the encoder sends for it', function () {
    $module = new TypeScriptGenerator()->generate([OmPage::class]);

    expect($module)->toContain("import type { OnceField } from '@tinvar/react/once';")
        ->and($module)->toContain("import type { MergeField, ScrollField } from '@tinvar/react/merge';")
        ->and($module)->toContain('  total: OnceField<number>;')
        ->and($module)->toContain('  countries: OnceField<OmItem[]>;')
        ->and($module)->toContain('  latest: MergeField<OmItem>;')
        ->and($module)->toContain('  items: ScrollField<OmItem>;')
        // Rescue applies to the resolved value, which once wraps.
        ->and($module)->toContain('  risky: OnceField<number | Rescued>;');
});

it('imports none of the types for a module that needs none', function () {
    $module = new TypeScriptGenerator()->generate([OmPlain::class]);

    expect($module)->not->toContain('OnceField')
        ->and($module)->not->toContain('MergeField')
        ->and($module)->not->toContain('ScrollField');
});

it('refuses a Data class named as a type the module imports', function () {
    expect(fn () => new TypeScriptGenerator()->generate([OnceField::class]))
        ->toThrow(CodegenException::class, 'named OnceField');
});

// The generated type and the encoder's output are one claim, so they are
// checked together: the keys the types declare are the keys sent.
it('describes what the encoder actually sends', function () {
    $page = new OmPage(
        total: Lazy::value(3),
        countries: Lazy::value([new OmItem('nz', 'New Zealand')]),
        latest: [new OmItem('a', 'A')],
        items: Scroll::paginate([new OmItem('b', 'B')], page: 1, perPage: 1, total: 2),
        risky: Lazy::value(1),
    );

    $sent = new Encoder()->encode($page, new PayloadContext(heldOnce: ['countries']), 'page');

    expect(array_keys($sent['total']))->toBe(['$once', 'ttl', 'tags', 'value'])
        ->and(array_keys($sent['countries']))->toBe(['$once', 'held'])
        ->and(array_keys($sent['latest']))->toBe(['$merge', 'mode', 'reset', 'matchOn', 'items'])
        ->and(array_keys($sent['items']))
        ->toBe(['$scroll', 'reset', 'page', 'previous', 'next', 'total', 'perPage', 'pageName', 'matchOn', 'items']);
});

it('compiles, and makes a component go through <Once> and <Merged> to read them', function () {
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-tsc-once-' . bin2hex(random_bytes(6));
    mkdir($dir);

    $files = [
        'generated.ts' => new TypeScriptGenerator()->generate([OmPage::class]),
        'consumer.tsx' => <<<'TSX'
            import { Once, isHeld } from '@tinvar/react/once';
            import { InfiniteScroll, Merged } from '@tinvar/react/merge';
            import type { OmPage } from './generated';

            export function Page({ data }: { data: OmPage }) {
              // @ts-expect-error held, the field is its key alone
              const total: number = data.total.value;

              return (
                <>
                  <Once field={data.total}>{(n) => <b>{n.toFixed(0)}</b>}</Once>
                  {isHeld(data.countries) ? null : data.countries.value.length}
                  <ul>
                    <Merged field={data.latest}>{(item) => <li key={item.id}>{item.title}</li>}</Merged>
                  </ul>
                  <InfiniteScroll data={data.items} manualAfter={2}>
                    {(item) => <p key={item.id}>{item.title}</p>}
                  </InfiniteScroll>
                  {total}
                </>
              );
            }
            TSX,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => [
                'noEmit' => true,
                'skipLibCheck' => false,
                'types' => [],
                'paths' => [
                    'react' => ["{$root}/node_modules/@types/react"],
                    'react/jsx-runtime' => ["{$root}/node_modules/@types/react/jsx-runtime"],
                    TypeScriptGenerator::ONCE_RUNTIME => ["{$root}/packages/react/src/once.d.ts"],
                    TypeScriptGenerator::MERGE_RUNTIME => ["{$root}/packages/react/src/merge.d.ts"],
                    TypeScriptGenerator::RESCUE_RUNTIME => ["{$root}/packages/react/src/rescue.d.ts"],
                ],
            ],
            'files' => ['generated.ts', 'consumer.tsx'],
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
        $exit = proc_close($process);
    } finally {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }

    expect($output)->toBe('')
        ->and($exit)->toBe(0);
})->skip(!is_file(TSC), 'TypeScript is not installed (npm ci).');
