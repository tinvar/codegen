<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\ChannelType;

/**
 * The #[Channel]s typed for the client (ADR 0069): a ChannelMap of each
 * one's parameters and the Data class its messages are, and a `channel`
 * built on the runtime -- compiled by tsc with useChannel(), so a missing
 * parameter or a wrong field of a message is a type error.
 */

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../../database/tests/Orm/Fixtures/models.php';

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

enum Shift: string
{
    case Day = 'day';
    case Night = 'night';
}

final readonly class RackMovedData extends Data
{
    public function __construct(public string $rack, public int $heightU) {}
}

#[Channel('racks.{rack}.{shift}.{floor}', message: RackMovedData::class), Authorize('view', 'rack')]
final readonly class RackMoves
{
    public function __construct(public Rack $rack, public Shift $shift, public int $floor) {}
}

function module(): string
{
    $registry = new ChannelRegistry();
    $registry->register(RackMoves::class);

    return (new TypeScriptGenerator())->files([], channels: $registry->all())['module'];
}

/** @return array{int, string} tsc's exit code and output over the module and $consumer */
function compile(string $consumer): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-channel-tsc-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $files = [
        'generated.ts' => module(),
        'consumer.ts' => $consumer,
        'tsconfig.json' => json_encode([
            'extends' => "{$root}/tsconfig.base.json",
            'compilerOptions' => [
                'noEmit' => true,
                'skipLibCheck' => false,
                'types' => [],
                'lib' => ['es2022', 'dom'],
                'paths' => [
                    TypeScriptGenerator::CHANNEL_RUNTIME => ["{$root}/packages/react/src/channel.d.ts"],
                    '@tinvar/react/use-channel' => ["{$root}/packages/react/src/use-channel.d.ts"],
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

        return [proc_close($process), $output];
    } finally {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

it('describes each channel by name: a model by its id, an enum by its values, its message by its Data', function () {
    expect(module())
        ->toContain("import { channels } from '@tinvar/react/channel';")
        ->toContain("export interface ChannelMap {\n"
            . "  'racks': { params: { rack: string; shift: 'day' | 'night'; floor: number }; message: RackMovedData };\n"
            . "}\n")
        ->toContain('export const channel = channels<ChannelMap>();')
        ->toContain('export interface RackMovedData');
});

it('writes nothing of channels for an application with none', function () {
    expect((new TypeScriptGenerator())->files([])['module'])
        ->not->toContain('ChannelMap')
        ->not->toContain('@tinvar/react/channel');
});

it('compiles a subscription with what the channel takes, and refuses one without', function () {
    [$ok, $output] = compile(<<<'TS'
        import { channel, type RackMovedData } from './generated';
        import { useChannel } from '@tinvar/react/use-channel';

        const off: () => void = channel.listen('racks', { rack: 'r-1', shift: 'day', floor: 2 }, (m: RackMovedData) => m.heightU);
        off();
        export function Probe(): null {
          useChannel(channel, 'racks', { rack: 'r-1', shift: 'night', floor: 1 }, (m) => m.rack.length);
          return null;
        }
        TS);
    expect($ok)->toBe(0, $output);

    [$bad, $errors] = compile(<<<'TS'
        import { channel } from './generated';
        channel.listen('racks', { rack: 'r-1', shift: 'evening', floor: 2 }, () => {});
        channel.listen('racks', { rack: 'r-1' }, () => {});
        channel.listen('racks', { rack: 'r-1', shift: 'day', floor: 2 }, (m) => m.nothing);
        channel.listen('shelves', {}, () => {});
        TS);
    expect($bad)->not->toBe(0)
        ->and(substr_count($errors, 'error TS'))->toBe(4);
});
