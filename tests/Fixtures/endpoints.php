<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\Fixtures\Endpoints;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Endpoint;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Post;
use Tinvar\Data\Data;
use Tinvar\Http\Response;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;

final readonly class RackStockData extends Data
{
    public function __construct(public string $rack, public int $free) {}
}

final readonly class ResizeRackRequest extends FormRequest
{
    public function __construct(public int $heightU, public ?string $note = null) {}
}

final readonly class MoveRackRequest extends FormRequest
{
    public function __construct(public Site $site) {}
}

final class RackEndpoints
{
    #[Get('/racks/{rack}/stock', name: 'racks.stock'), Authorize('view', 'rack')]
    public function stock(Rack $rack): RackStockData
    {
        return new RackStockData($rack->id, 0);
    }

    #[Post('/racks/{rack}/resize', name: 'racks.resize'), Authorize('update', 'rack')]
    public function resize(Rack $rack, ResizeRackRequest $request): void {}

    #[Endpoint('/racks/{rack}/site', methods: ['PUT', 'PATCH'], name: 'racks.move'), Authorize('update', 'rack'), Authorize('update', 'site')]
    public function move(Rack $rack, MoveRackRequest $request): RackStockData
    {
        return new RackStockData($rack->id, 0);
    }

    #[Get('/racks/{rack}/label.pdf', name: 'racks.label'), Authorize('view', 'rack')]
    public function label(Rack $rack): Response
    {
        return Response::make(200, '%PDF');
    }
}
