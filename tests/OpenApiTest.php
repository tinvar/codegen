<?php

declare(strict_types=1);

/**
 * The OpenAPI document (ADR 0159): what `tinvar openapi` describes of the
 * actions, queries and endpoints marked #[RequiresScope] -- inputs with
 * their rules as constraints, answers as codegen types them, scopes on the
 * bearer scheme -- and what it refuses to describe. (Its validity against
 * the OpenAPI 3.1 schema is openapi.test.ts's, with Ajv.)
 */

namespace Tinvar\Tests\Codegen\OpenApi;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\OpenApi\OpenApiCommand;
use Tinvar\Codegen\OpenApi\SchemaMapper;
use Tinvar\Data\Data;
use Tinvar\Data\Scroll;
use Tinvar\Support\Lazy;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;

enum Shade: string
{
    case Light = 'light';
    case Dark = 'dark';
}

final readonly class SwatchData extends Data
{
    /**
     * @param list<string> $tags
     * @param array<string, int> $counts
     * @param Lazy<list<SwatchData>> $related
     */
    public function __construct(
        public string $id,
        public ?Shade $shade,
        public CarbonImmutable $madeAt,
        public ?CarbonImmutable $retiredAt,
        public array $tags,
        public array $counts,
        public Lazy $related,
    ) {}
}

final readonly class PagedData extends Data
{
    /** @param Scroll<SwatchData> $items */
    public function __construct(public Scroll $items) {}
}

final readonly class RenameRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(40)]
        public string $name,
        #[Email]
        public string $contact,
        #[Pattern('[A-Z]{3}')]
        public string $code,
        public ?Shade $shade = null,
    ) {}
}

#[Query(name: 'swatches.show'), Authorize('view', SwatchData::class), RequiresScope('swatches:read')]
final class ShowSwatch
{
    /** One swatch, as it is. */
    public function __invoke(string $id, Shade $shade = Shade::Light): SwatchData
    {
        throw new \LogicException('described, never run');
    }
}

final class SwatchActions
{
    /**
     * Renames a swatch.
     *
     * @return ActionResult<SwatchData>
     */
    #[Action(name: 'swatches.rename'), Authorize('update', SwatchData::class), RequiresScope('swatches:write', 'swatches:read')]
    public function rename(RenameRequest $request): ActionResult
    {
        return ActionResult::ok();
    }

    #[Action(name: 'swatches.hidden'), Authorize('update', SwatchData::class)]
    public function hidden(): ActionResult
    {
        return ActionResult::ok();
    }
}

final class SwatchEndpoints
{
    /** A swatch's label, for a printer. */
    #[Get('/api/swatches/{id}/label', name: 'swatches.label'), Authorize('view', SwatchData::class), RequiresScope('swatches:read')]
    public function label(string $id): SwatchData
    {
        throw new \LogicException('described, never run');
    }
}

/** @return array<string, mixed> */
function described(): array
{
    return new OpenApiCommand('Swatches', [SwatchActions::class], [ShowSwatch::class], [SwatchEndpoints::class])->document()->toArray();
}

it('describes each action, query and endpoint marked #[RequiresScope], and nothing else', function () {
    $document = described();

    expect(array_keys($document['paths']))->toBe(['/_api/swatches.rename', '/_api/swatches.show', '/api/swatches/{id}/label'])
        ->and($document['paths']['/_api/swatches.rename']['post']['security'])->toBe([['apiToken' => ['swatches:read', 'swatches:write']]])
        ->and($document['paths']['/_api/swatches.rename']['post']['summary'])->toBe('Renames a swatch.')
        ->and($document['paths']['/api/swatches/{id}/label']['get']['parameters'][0])->toMatchArray(['name' => 'id', 'in' => 'path', 'required' => true])
        ->and($document['components']['securitySchemes']['apiToken'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($document['components']['securitySchemes']['apiToken']['description'])->toContain('swatches:read, swatches:write');
});

it('turns an input\'s rules into constraints', function () {
    $input = described()['paths']['/_api/swatches.rename']['post']['requestBody']['content']['application/json']['schema'];

    expect($input['properties']['name'])->toBe(['type' => 'string', 'minLength' => 1, 'maxLength' => 40])
        ->and($input['properties']['contact'])->toBe(['type' => 'string', 'format' => 'email'])
        ->and($input['properties']['code'])->toBe(['type' => 'string', 'pattern' => '^(?:[A-Z]{3})$'])
        ->and($input['properties']['shade'])->toBe(['type' => ['string', 'null'], 'enum' => ['light', 'dark', null]])
        ->and($input['required'])->toBe(['name', 'contact', 'code'])
        ->and($input['additionalProperties'])->toBeFalse();
});

it('describes answers as codegen types them: Data, enums, moments, nullables, lists, maps and Lazy', function () {
    $document = described();
    $swatch = $document['components']['schemas']['SwatchData'];

    expect($document['paths']['/_api/swatches.show']['post']['responses']['200']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/SwatchData'])
        ->and($document['paths']['/_api/swatches.rename']['post']['responses']['200']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/SwatchData'])
        ->and($swatch['properties']['shade'])->toBe(['anyOf' => [['$ref' => '#/components/schemas/Shade'], ['type' => 'null']]])
        ->and($swatch['properties']['madeAt'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($swatch['properties']['retiredAt'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($swatch['properties']['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($swatch['properties']['counts'])->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])
        ->and($swatch['properties']['related'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SwatchData']])
        ->and($swatch['required'])->toBe(['id', 'shade', 'madeAt', 'retiredAt', 'tags', 'counts', 'related'])
        ->and($document['components']['schemas']['Shade'])->toBe(['type' => 'string', 'enum' => ['light', 'dark']]);
});

it('names every refusal by the framework\'s shapes', function () {
    $document = described();

    expect(array_keys($document['paths']['/_api/swatches.show']['post']['responses']))->toBe([200, 401, 403, 404, 422, 429, 500])
        ->and($document['components']['responses']['TooManyRequests']['headers'])->toHaveKey('Retry-After')
        ->and($document['components']['schemas']['ValidationErrors']['properties']['errors']['additionalProperties'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('refuses a page\'s Scroll, which an API call does not answer with', function () {
    expect(fn () => new SchemaMapper()->reference(PagedData::class, 'test'))->toThrow(CodegenException::class, 'an API call does not answer with');
});

final readonly class ShadedData extends Data
{
    /** @param value-of<Shade> $tone */
    public function __construct(public string $tone) {}
}

final readonly class ShadeListData extends Data
{
    /** @param list<Shade> $shades */
    public function __construct(public array $shades) {}
}

final readonly class MutableMomentData extends Data
{
    public function __construct(public \DateTime $at) {}
}

it('reads a field as codegen and the encoder do: value-of<> as the enum, an enum or a moment only as a field declared so', function () {
    $mapper = new SchemaMapper();
    $mapper->reference(ShadedData::class, 'test');

    expect($mapper->components()['ShadedData']['properties']['tone'])->toBe(['$ref' => '#/components/schemas/Shade'])
        // The encoder sends an enum's value only for a field declared as
        // the enum, and refuses one in a list, as codegen's TypeScript does.
        ->and(fn () => new SchemaMapper()->reference(ShadeListData::class, 'test'))->toThrow(CodegenException::class)
        // A mutable moment is never sent: only an immutable one is.
        ->and(fn () => new SchemaMapper()->reference(MutableMomentData::class, 'test'))->toThrow(CodegenException::class);
});

final readonly class ByIdData extends Data
{
    /** @param array<int, string> $names */
    public function __construct(public array $names) {}
}

it('describes an int-keyed array as the list or the object the encoder may send it as', function () {
    $mapper = new SchemaMapper();
    $mapper->reference(ByIdData::class, 'test');

    // A list when its keys run 0, 1, 2...; an object of those keys when
    // they do not, as codegen's Record<number, string> allows either.
    expect($mapper->components()['ByIdData']['properties']['names'])->toBe(['anyOf' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
    ]]);
});

it('writes, checks, and skips an application with no API', function () {
    $file = sys_get_temp_dir() . '/tinvar-openapi-' . bin2hex(random_bytes(4)) . '/openapi.json';
    $command = new OpenApiCommand('Swatches', [SwatchActions::class], [ShowSwatch::class], [SwatchEndpoints::class]);
    $none = new OpenApiCommand('Nothing');

    ob_start();
    try {
        expect($command->run(["--out={$file}"]))->toBe(0)
            ->and($command->run(["--out={$file}", '--check']))->toBe(0)
            ->and($none->describesAny())->toBeFalse()
            ->and($none->run(['--out=' . \dirname($file) . '/none.json', '--if-any']))->toBe(0)
            ->and(is_file(\dirname($file) . '/none.json'))->toBeFalse();
        file_put_contents($file, '{}');
        expect(@$command->run(["--out={$file}", '--check']))->toBe(1);
    } finally {
        ob_end_clean();
        @unlink($file);
        @rmdir(\dirname($file));
    }
});
