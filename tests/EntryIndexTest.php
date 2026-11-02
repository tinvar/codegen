<?php

declare(strict_types=1);

/**
 * entries.json (ADR 0158): each entry point codegen types, with the class,
 * method and file that answer it, and what a view reaches it by -- what the
 * VS Code extension reads to go between a component and its PHP, and to
 * list the routes, without parsing PHP.
 */

namespace Tinvar\Tests\Codegen\EntryIndex;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Query;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Codegen\CodegenCommand;

#[Layout('/', view: 'back/layout'), Domain('admin.{root}'), Panel('staff')]
final class BackLayout {}

#[Page('/orders', view: 'back/orders', name: 'back.orders'), Domain('admin.{root}')]
final class BackOrders {}

#[Page('/', view: 'front/home', name: 'front.home')]
final class FrontHome {}

#[Query(name: 'orders.recent'), PublicAccess('a test')]
final class RecentOrders
{
    public function __invoke(): string
    {
        return '';
    }
}

final class OrderActions
{
    #[Action(name: 'orders.place'), PublicAccess('a test')]
    public function place(string $sku): ActionResult
    {
        return ActionResult::ok();
    }
}

final class StatusEndpoint
{
    #[Get('/status', name: 'status'), PublicAccess('a test')]
    public function status(): void {}
}

/** @return list<array<string, mixed>> */
function entries(?PanelRegistry $panels = null, string $out = __DIR__ . '/out/types'): array
{
    $files = new CodegenCommand(
        $out,
        queries: [RecentOrders::class],
        routes: [BackLayout::class, BackOrders::class, FrontHome::class],
        actions: [OrderActions::class],
        endpoints: [StatusEndpoint::class],
        panels: $panels,
    )->files();
    $decoded = json_decode($files['entries.json'], true, 16, JSON_THROW_ON_ERROR);
    \assert(\is_array($decoded) && $decoded['format'] === 1 && \is_array($decoded['entries']) && array_is_list($decoded['entries']));

    /** @var list<array<string, mixed>> */
    return $decoded['entries'];
}

/**
 * @param list<array<string, mixed>> $entries
 * @return array<string, mixed>
 */
function entry(array $entries, string $kind, ?string $name): array
{
    foreach ($entries as $entry) {
        if ($entry['kind'] === $kind && $entry['name'] === $name) {
            return $entry;
        }
    }

    throw new \RuntimeException("No {$kind} named {$name}.");
}

it('names each page by its view, path, host and panel, a layout around it giving the panel', function () {
    $entries = entries();

    expect(entry($entries, 'page', 'back.orders'))->toBe([
        'kind' => 'page',
        'name' => 'back.orders',
        'class' => BackOrders::class,
        'method' => '__invoke',
        'file' => '../../EntryIndexTest.php',
        'view' => 'back/orders',
        'path' => '/orders',
        'host' => 'admin.{root}',
        'panel' => 'staff',
    ])
        ->and(entry($entries, 'page', 'front.home'))->toMatchArray(['host' => null, 'panel' => null, 'view' => 'front/home'])
        ->and(entry($entries, 'layout', null))->toMatchArray(['class' => BackLayout::class, 'view' => 'back/layout', 'panel' => 'staff']);
});

it('names a query by the @queries module and function a view imports', function () {
    expect(entry(entries(), 'query', 'orders.recent'))->toMatchArray([
        'class' => RecentOrders::class,
        'method' => '__invoke',
        'module' => '@queries/orders',
        'export' => 'recent',
    ]);
});

it('names an action by the @actions module and export a view imports, and an endpoint by its methods and path', function () {
    $entries = entries();

    expect(entry($entries, 'action', 'orders.place'))->toMatchArray([
        'class' => OrderActions::class,
        'method' => 'place',
        'module' => '@actions/OrderActions',
        'export' => 'place',
    ])
        ->and(entry($entries, 'endpoint', 'status'))->toMatchArray(['method' => 'status', 'methods' => ['GET'], 'path' => '/status']);
});

it('gives an entry point declaring no panel its namespace\'s, where the application has one', function () {
    $panels = new PanelRegistry(new PanelDefinition('front', namespaces: ['Tinvar\\Tests\\Codegen\\EntryIndex\\']));

    expect(entry(entries($panels), 'page', 'front.home')['panel'])->toBe('front')
        ->and(entry(entries($panels), 'page', 'back.orders')['panel'])->toBe('staff');
});

it('names each file from the directory the index is written in, wherever that is', function () {
    expect(entry(entries(out: __DIR__ . '/a/b/c/types'), 'query', 'orders.recent')['file'])->toBe('../../../../EntryIndexTest.php')
        ->and(entry(entries(out: __DIR__), 'query', 'orders.recent')['file'])->toBe('EntryIndexTest.php');
});

it('writes entries.json beside the types, which --check then finds current', function () {
    $out = sys_get_temp_dir() . '/tinvar-entries-' . bin2hex(random_bytes(4));
    $command = new CodegenCommand($out, queries: [RecentOrders::class]);

    ob_start();
    try {
        expect($command->run([]))->toBe(0)
            ->and(json_decode((string) file_get_contents("{$out}/entries.json"), true)['entries'][0]['name'] ?? null)->toBe('orders.recent')
            ->and($command->run(['--check']))->toBe(0);
    } finally {
        ob_end_clean();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($out);
    }
});
