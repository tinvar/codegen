<?php

declare(strict_types=1);

/**
 * The type of an #[Optional] field: `T | Unrequested`, because that is what
 * the encoder sends -- the value when a partial reload named the field, and
 * the path that would have otherwise. A type of `T` would have a component
 * read `.map` off the marker on every full render; ADR 0045.
 */

namespace Tinvar\Tests\Codegen\OptionalType;

use Tinvar\Attributes\Always;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Rescue;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;

final readonly class OtEvent extends Data
{
    public function __construct(public string $title) {}
}

final readonly class OtTicket extends Data
{
    /**
     * @param Lazy<list<OtEvent>> $history
     * @param Lazy<int>           $flaky
     * @param Lazy<int>           $totals
     */
    public function __construct(
        #[Always]
        public string $subject,
        #[Optional]
        public Lazy $history,
        #[Optional, Rescue]
        public Lazy $flaky,
        #[Optional, Once(key: 'tickets.totals')]
        public Lazy $totals,
    ) {}
}

final readonly class OtNotLazy extends Data
{
    public function __construct(#[Optional] public string $title) {}
}

final readonly class Unrequested extends Data
{
    /** @param Lazy<int> $n */
    public function __construct(#[Optional] public Lazy $n) {}
}

final readonly class OtPlain extends Data
{
    /** @param Lazy<int> $n */
    public function __construct(public Lazy $n) {}
}

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

it('types an optional field as its value or the path that asks for it', function () {
    $module = new TypeScriptGenerator()->generate([OtTicket::class]);

    expect($module)->toContain("import type { Unrequested } from '@tinvar/react/optional';")
        ->and($module)->toContain('  history: OtEvent[] | Unrequested;')
        ->and($module)->toContain('  flaky: number | Rescued | Unrequested;')
        ->and($module)->toContain('  totals: OnceField<number> | Unrequested;')
        // #[Always] changes which segments are sent, not what a field is.
        ->and($module)->toContain('  subject: string;');
});

it('imports the marker type only for a module that needs it', function () {
    expect(new TypeScriptGenerator()->generate([OtPlain::class]))->not->toContain('Unrequested');
});

// One claim, checked together: what the encoder sends for an unasked field
// is the shape the union's other side declares.
it('describes the marker the encoder actually sends', function () {
    $encoded = new Encoder()->encode(new OtTicket('Printer', Lazy::value([]), Lazy::value(1), Lazy::value(2)), segment: 'page');

    expect($encoded['history'])->toBe([Encoder::OPTIONAL_KEY => 'page.history'])
        ->and(Encoder::OPTIONAL_KEY)->toBe('$optional');
});

it('refuses #[Optional] on a field that is not Lazy', function () {
    expect(fn () => new TypeScriptGenerator()->generate([OtNotLazy::class]))
        ->toThrow(\LogicException::class, 'only a Lazy field can be');
});

it('refuses a Data class named Unrequested beside an optional field', function () {
    expect(fn () => new TypeScriptGenerator()->generate([Unrequested::class]))
        ->toThrow(CodegenException::class, 'named Unrequested');
});

it('compiles, and makes a component narrow an optional field before reading it', function () {
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-tsc-optional-' . bin2hex(random_bytes(6));
    mkdir($dir);

    $files = [
        'generated.ts' => new TypeScriptGenerator()->generate([OtTicket::class]),
        'consumer.ts' => <<<'TS'
            import { isUnrequested } from '@tinvar/react/optional';
            import type { OtTicket } from './generated';

            export function titles(ticket: OtTicket): string[] {
              // @ts-expect-error the field may be the marker, which has no map()
              ticket.history.map((e) => e.title);

              return isUnrequested(ticket.history) ? [ticket.history.$optional] : ticket.history.map((e) => e.title);
            }
            TS,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => [
                'noEmit' => true,
                'skipLibCheck' => false,
                'types' => [],
                'paths' => [
                    TypeScriptGenerator::OPTIONAL_RUNTIME => ["{$root}/packages/react/src/optional.d.ts"],
                    TypeScriptGenerator::RESCUE_RUNTIME => ["{$root}/packages/react/src/rescue.d.ts"],
                    TypeScriptGenerator::ONCE_RUNTIME => ["{$root}/packages/react/src/once.d.ts"],
                ],
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
