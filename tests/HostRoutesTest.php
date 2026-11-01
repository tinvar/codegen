<?php

declare(strict_types=1);

/**
 * The generated route() for pages on hosts of their own (ADR 0149): the
 * table entry carries the host pattern, and RouteMap types a host parameter
 * beside the path's, so route() takes both and writes the absolute URL.
 */

namespace Tinvar\Tests\Codegen\HostRoutes;

use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Page;
use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Routing\RouteRegistry;

#[Page('/orders', view: 'shop/orders', name: 'shop.orders')]
final class ShopOrders {}

#[Page('/orders', view: 'admin/orders', name: 'admin.orders'), Domain('admin.{root}')]
final class AdminOrders {}

#[Page('/invoices/{invoice}', view: 'accounts/invoice', name: 'accounts.invoice'), Domain('{account}.{root}')]
final class AccountInvoice
{
    public function __invoke(string $account, string $invoice): void {}
}

function emitted(): string
{
    $registry = new RouteRegistry();
    $registry->registerAll([ShopOrders::class, AdminOrders::class, AccountInvoice::class]);

    return new TypeScriptGenerator()->files([], [], array_map(RouteTypes::of(...), $registry->pages()), [])['module'];
}

it('writes a primary-host route as its segments, and another host\'s with its pattern', function () {
    expect(emitted())
        ->toContain("'shop.orders': ['orders'],")
        ->toContain("'admin.orders': { segments: ['orders'], host: 'admin.{root}' },")
        ->toContain("'accounts.invoice': { segments: ['invoices', '{invoice}'], host: '{account}.{root}' },");
});

it('types a host parameter beside the path\'s', function () {
    expect(emitted())->toContain("'accounts.invoice': { account: string; invoice: string };");
});
