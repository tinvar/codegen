<?php

declare(strict_types=1);

/**
 * The type of a #[Rescue] field: `T | Rescued`, because that is what the
 * encoder sends -- the value, or the marker when its resolver threw. A type
 * of `T` would have a component read `.map` off the marker; ADR 0017.
 */

namespace Tinvar\Tests\Codegen\RescueType;

use Tinvar\Attributes\Rescue;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;

final readonly class RtEvent extends Data
{
    public function __construct(public string $title) {}
}

final readonly class RtOrder extends Data
{
    /**
     * @param Lazy<list<RtEvent>> $events
     * @param Lazy<string>        $tracking
     * @param Lazy<int>           $count
     */
    public function __construct(
        #[Rescue]
        public Lazy $events,
        #[Rescue]
        public ?Lazy $tracking,
        public Lazy $count,
    ) {}
}

final readonly class RtNotLazy extends Data
{
    public function __construct(#[Rescue] public string $title) {}
}

final readonly class Rescued extends Data
{
    /** @param Lazy<int> $n */
    public function __construct(#[Rescue] public Lazy $n) {}
}

final readonly class RtPlain extends Data
{
    /** @param Lazy<int> $n */
    public function __construct(public Lazy $n) {}
}

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

it('types a rescued Lazy field as its value or the marker', function () {
    $module = new TypeScriptGenerator()->generate([RtOrder::class]);

    expect($module)->toContain("import type { Rescued } from '@tinvar/react/rescue';")
        ->and($module)->toContain('  events: RtEvent[] | Rescued;')
        ->and($module)->toContain('  tracking: string | null | Rescued;')
        // Not marked, so never sent as the marker, so not typed as one.
        ->and($module)->toContain('  count: number;');
});

it('imports the marker type only for a module that needs it', function () {
    expect(new TypeScriptGenerator()->generate([RtPlain::class]))->not->toContain('Rescued');
});

// The generated type and the encoder's output are one claim, so they are
// checked together: what the encoder sends for a rescued field is the shape
// the generated union's other side declares.
it('describes the marker the encoder actually sends', function () {
    $encoded = new Encoder(static fn (\Throwable $e, ?string $d): string => 'd1')->encode(new RtOrder(
        Lazy::of(static fn () => throw new \RuntimeException('down')),
        Lazy::value(null),
        Lazy::value(1),
    ));

    expect($encoded['events'])->toBe([Encoder::RESCUED_KEY => 'd1'])
        ->and(Encoder::RESCUED_KEY)->toBe('$rescued');
});

it('refuses #[Rescue] on a field that is not Lazy', function () {
    expect(fn () => new TypeScriptGenerator()->generate([RtNotLazy::class]))
        ->toThrow(CodegenException::class, 'only a Lazy field can be');
});

it('refuses a Data class named Rescued beside a rescued field', function () {
    expect(fn () => new TypeScriptGenerator()->generate([Rescued::class]))
        ->toThrow(CodegenException::class, 'named Rescued');
});

it('compiles, and makes a component narrow a rescued field before reading it', function () {
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-tsc-rescue-' . bin2hex(random_bytes(6));
    mkdir($dir);

    $files = [
        'generated.ts' => new TypeScriptGenerator()->generate([RtOrder::class]),
        'consumer.ts' => <<<'TS'
            import { isRescued } from '@tinvar/react/rescue';
            import type { RtOrder } from './generated';

            export function titles(order: RtOrder): string[] {
              // @ts-expect-error the field may be the marker, which has no map()
              order.events.map((e) => e.title);

              return isRescued(order.events) ? [order.events.$rescued] : order.events.map((e) => e.title);
            }
            TS,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => [
                'noEmit' => true,
                'skipLibCheck' => false,
                'types' => [],
                'paths' => [TypeScriptGenerator::RESCUE_RUNTIME => ["{$root}/packages/react/src/rescue.d.ts"]],
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
