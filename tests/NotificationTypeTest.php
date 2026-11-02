<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\NotificationType;

/**
 * The #[Notification]s and #[Inbox]es typed for the client (ADR 0155): a
 * NotificationMap of each kind's channels, NotificationType, and an
 * `inbox` built on the runtime -- compiled by tsc with useInbox() and
 * <Inbox>, so an inbox there is none of, or a channel a kind does not
 * send on, is a type error.
 */

use Tinvar\Attributes\Inbox;
use Tinvar\Attributes\Notification;
use Tinvar\Auth\Authenticatable;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Notifications\InboxMessage;
use Tinvar\Notifications\InboxNotification;
use Tinvar\Notifications\InboxRegistry;
use Tinvar\Notifications\NotificationRegistry;
use Tinvar\Notifications\PushNotification;

const ROOT = __DIR__ . '/../../..';
const TSC = ROOT . '/node_modules/.bin/tsc';

#[Notification('racks.moved', label: 'A rack moved')]
final readonly class RackMoved implements InboxNotification, PushNotification
{
    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return new InboxMessage('A rack moved');
    }

    public function push(Authenticatable $user): ?Data
    {
        return null;
    }
}

#[Notification('racks.full', label: 'A rack is full')]
final readonly class RackFull implements InboxNotification
{
    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return new InboxMessage('A rack is full');
    }
}

#[Inbox('staff')]
final readonly class StaffInbox {}

#[Inbox('customer')]
final readonly class CustomerInbox {}

function module(bool $inboxes = true): string
{
    return (new TypeScriptGenerator())->files(
        [],
        notifications: NotificationRegistry::of([RackMoved::class, RackFull::class])->all(),
        inboxes: $inboxes ? InboxRegistry::of([StaffInbox::class, CustomerInbox::class])->all() : [],
    )['module'];
}

/** @return array{int, string} tsc's exit code and output over the module and $consumer */
function compile(string $consumer): array
{
    $root = realpath(ROOT);
    $dir = sys_get_temp_dir() . '/tinvar-inbox-tsc-' . bin2hex(random_bytes(6));
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
                'jsx' => 'react-jsx',
                'typeRoots' => ["{$root}/node_modules/@types"],
                'paths' => [
                    TypeScriptGenerator::INBOX_RUNTIME => ["{$root}/packages/react/src/inbox.d.ts"],
                    '@tinvar/react/use-inbox' => ["{$root}/packages/react/src/use-inbox.d.ts"],
                    '@tinvar/react/inbox-view' => ["{$root}/packages/react/src/inbox-view.d.ts"],
                    'react' => ["{$root}/node_modules/@types/react"],
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

it('describes each kind by type with the channels it sends on, and an inbox client for each by name', function () {
    expect(module())
        ->toContain("import { inboxes } from '@tinvar/react/inbox';")
        ->toContain("export interface NotificationMap {\n"
            . "  'racks.full': { channels: 'inbox' };\n"
            . "  'racks.moved': { channels: 'push' | 'inbox' };\n"
            . "}\n")
        ->toContain('export type NotificationType = keyof NotificationMap & string;')
        ->toContain("export const inbox = inboxes<NotificationMap, 'customer' | 'staff'>(['customer', 'staff']);");
});

it('writes the kinds without an inbox, and nothing for an application with neither', function () {
    expect(module(inboxes: false))->toContain('NotificationMap')->not->toContain('@tinvar/react/inbox')
        ->and((new TypeScriptGenerator())->files([])['module'])->not->toContain('NotificationMap');
});

it('compiles an inbox read by name, and refuses one there is none of', function () {
    [$ok, $output] = compile(<<<'TS'
        import { inbox, type NotificationType } from './generated';
        import { useInbox, useNotificationPreferences } from '@tinvar/react/use-inbox';
        import { Inbox } from '@tinvar/react/inbox-view';
        import { createElement } from 'react';

        export function Probe(): unknown {
          const { entries, markRead } = useInbox(inbox.customer);
          const kind: NotificationType | undefined = entries[0]?.type;
          void markRead(entries.map((e) => e.id));
          const prefs = useNotificationPreferences(inbox.staff);
          void prefs.choose('racks.moved', 'push', false);
          return [kind, createElement(Inbox, { client: inbox.customer })];
        }
        TS);
    expect($ok)->toBe(0, $output);

    [$bad, $errors] = compile(<<<'TS'
        import { inbox } from './generated';
        import { useNotificationPreferences } from '@tinvar/react/use-inbox';
        void inbox.warehouse;
        export function Probe(): void {
          void useNotificationPreferences(inbox.staff).choose('racks.gone', 'inbox', true);
        }
        TS);
    expect($bad)->not->toBe(0)
        ->and(substr_count($errors, 'error TS'))->toBe(2);
});
