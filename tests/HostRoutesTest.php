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

final class FeedEndpoints
{
    #[\Tinvar\Attributes\Get('/feed', name: 'admin.feed'), Domain('admin.{root}'), \Tinvar\Attributes\NoAuthorization(reason: 'a test endpoint')]
    public function adminFeed(): void {}

    #[\Tinvar\Attributes\Get('/status', name: 'shop.status'), \Tinvar\Attributes\NoAuthorization(reason: 'a test endpoint')]
    public function status(): void {}
}

// An endpoint on a host of its own is called on that host: its table entry
// carries the pattern as a page's does, so endpoint() writes a path there
// and refuses to send it from anywhere else.
it('writes an endpoint on another host with its pattern', function () {
    $registry = new \Tinvar\Endpoints\EndpointRegistry();
    $registry->register(FeedEndpoints::class);
    $module = new TypeScriptGenerator()->files([], endpoints: $registry->all())['module'];

    expect($module)
        ->toContain("'admin.feed': { methods: ['GET'], segments: ['feed'], host: 'admin.{root}', returns: 'nothing' },")
        ->toContain("'shop.status': { methods: ['GET'], segments: ['status'], returns: 'nothing' },");
});

final class AccountEndpoints
{
    #[\Tinvar\Attributes\Get('/invoices/{invoice}', name: 'account.invoice'), Domain('{account}.{root}'), \Tinvar\Attributes\NoAuthorization(reason: 'a test endpoint')]
    public function invoice(string $account, string $invoice): void {}
}

// endpoint() writes the URL of one on a host a parameter takes with route(),
// which needs that parameter to name the host -- to write a path on acme's
// own subdomain, and acme's absolute URL from anywhere else. Typed only with
// the path's, it could not be given.
it('types an endpoint\'s host parameter beside its path\'s', function () {
    $registry = new \Tinvar\Endpoints\EndpointRegistry();
    $registry->register(AccountEndpoints::class);
    $module = new TypeScriptGenerator()->files([], endpoints: $registry->all())['module'];

    expect($module)
        ->toContain("'account.invoice': { params: { account: string; invoice: string };")
        ->toContain("'account.invoice': { methods: ['GET'], segments: ['invoices', '{invoice}'], host: '{account}.{root}', returns: 'nothing' },");
});
