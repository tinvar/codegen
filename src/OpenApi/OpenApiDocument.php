<?php

declare(strict_types=1);

namespace Tinvar\Codegen\OpenApi;

use Tinvar\Api\ApiEndpoint;
use Tinvar\Api\ApiTokenSecret;
use Tinvar\Calls\InputSchema;
use Tinvar\Calls\Operation;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\DocBlock;
use Tinvar\Endpoints\CompiledEndpoint;
use Tinvar\Orm\Model;
use Tinvar\Query\BatchQuery;
use Tinvar\Routing\Domains;
use Tinvar\Validation\FieldPlan;

/**
 * An application's public API as an OpenAPI 3.1 document (ADR 0159): every
 * action and query marked #[RequiresScope], at `POST /_api/{name}`, and
 * every endpoint marked so, at its own path -- each with what it takes,
 * what it answers, the scopes a token needs for it, and every way it can
 * be refused.
 *
 *   - **What it takes** is read where the call is validated: an
 *     operation's inputs as InputSchema reads them for a tool (types, a
 *     backed enum's values, #[In], #[Min], #[Max], #[Email], #[Pattern]),
 *     an endpoint's path parameters and form request fields alike.
 *   - **What it answers** is its return type, mapped by SchemaMapper: Data
 *     classes and enums as components, moments as date-time. An action
 *     answers with its ActionResult's value, which no type says: its
 *     docblock may (`@return ActionResult<OrderData>`), or the schema is
 *     any value.
 *   - **Who may call it**: the `apiToken` bearer scheme, `tvr_` tokens,
 *     with the scopes each requires listed in its security requirement
 *     (OpenAPI 3.1 lets a non-OAuth scheme name roles there) and every
 *     scope described on the scheme.
 *   - **Refusals** share the framework's shapes: 401 and 403 with
 *     WWW-Authenticate, 404, 422 with the validator's field errors, 429
 *     with Retry-After, 500 with a digest.
 *
 * Deterministic: paths and components sorted, so the build's artifact
 * changes only when the API does.
 */
final class OpenApiDocument
{
    public const string VERSION = '3.1.0';

    private SchemaMapper $schemas;

    /**
     * @param list<array{Operation, list<string>}> $operations each, and the scopes it requires
     * @param list<CompiledEndpoint> $endpoints those that take API tokens
     */
    public function __construct(
        private array $operations,
        private array $endpoints,
        private string $title,
        private string $version = '1.0.0',
        private ?Domains $domains = null,
    ) {
        $this->schemas = new SchemaMapper();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $this->schemas = new SchemaMapper();
        $paths = [];
        $scopes = [];

        foreach ($this->operations as [$operation, $required]) {
            $path = ApiEndpoint::PREFIX . $operation->name;
            $paths[$path] = ['post' => $this->operation($operation, $required)];
            array_push($scopes, ...$required);
        }
        foreach ($this->endpoints as $endpoint) {
            foreach ($endpoint->methods as $method) {
                $paths[$endpoint->template][strtolower($method)] = $this->endpoint($endpoint, $method);
            }
            array_push($scopes, ...$endpoint->scopes);
        }
        ksort($paths);
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        $document = [
            'openapi' => self::VERSION,
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => 'Every call takes an API token, `Authorization: Bearer tvr_...`, holding the scopes the call names, '
                    . 'and no cookie. A token is issued by its user, bound to their account, tenant and host, and ends when '
                    . 'revoked, expired, or when they sign out everywhere.',
            ],
            'servers' => [['url' => $this->domains === null ? '/' : $this->domains->origin(null)]],
            'paths' => $paths === [] ? new \stdClass() : $paths,
            'components' => [
                'securitySchemes' => [
                    'apiToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'tvr_<id>_<secret>',
                        'description' => 'An API token its user issued. Scopes: ' . ($scopes === [] ? 'none.' : implode(', ', $scopes) . '.')
                            . ' A token matches `' . ApiTokenSecret::SCANNER_PATTERN . '`, which secret scanners may look for.',
                    ],
                ],
                'responses' => self::refusals(),
                'schemas' => [...$this->schemas->components(), ...self::errorSchemas()],
            ],
        ];
        ksort($document['components']['schemas']);

        return $document;
    }

    /** As JSON, pretty and with a newline: the file `tinvar openapi` writes. */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function operation(Operation $operation, array $scopes): array
    {
        $where = $operation->kind === Operation::ACTION ? "The action `{$operation->name}`" : "The query `{$operation->name}`";
        $input = InputSchema::of($operation);
        $described = [
            'operationId' => $operation->name,
            'tags' => [explode('.', $operation->name)[0]],
            'summary' => $this->summary($operation) ?? $operation->name,
            'security' => [['apiToken' => $scopes]],
            'requestBody' => [
                'required' => $input['required'] !== [],
                'content' => ['application/json' => ['schema' => $input]],
            ],
            // Joined with +, never spread: a spread renumbers '401' and the
            // other status keys, which PHP holds as integers.
            'responses' => [
                '200' => [
                    'description' => $operation->kind === Operation::ACTION ? 'Done: what the action answers with.' : 'The query\'s value.',
                    'content' => ['application/json' => ['schema' => $this->answerOf($operation, $where)]],
                ],
            ] + self::refusalRefs($operation->kind === Operation::ACTION),
        ];
        $servers = $this->serversFor($operation->hosts);
        if ($servers !== []) {
            $described['servers'] = $servers;
        }

        return $described;
    }

    /** @return array<string, mixed> */
    private function endpoint(CompiledEndpoint $endpoint, string $method): array
    {
        $parameters = [];
        foreach ($endpoint->parameters as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => isset($endpoint->models[$name])
                    ? ['type' => 'string', 'description' => 'The id of a ' . self::shortName($endpoint->models[$name]) . '.']
                    : $this->scalarSchema($endpoint->scalars[$name] ?? 'string', "{$endpoint->where()} parameter {$name}"),
            ];
        }

        $fields = [];
        foreach ($endpoint->formRequests as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $field) {
                $property = $reflection->hasProperty($field->getName()) ? $reflection->getProperty($field->getName()) : null;
                $fields[$field->getName()] = FieldPlan::of($field, $class, $property);
            }
        }

        $described = [
            'operationId' => $endpoint->name . ($endpoint->methods === [$method] ? '' : '.' . strtolower($method)),
            'tags' => [explode('.', $endpoint->name)[0]],
            'summary' => self::docSummary(new \ReflectionMethod($endpoint->class, $endpoint->method))
                ?? self::docSummary(new \ReflectionClass($endpoint->class)) ?? $endpoint->name,
            'security' => [['apiToken' => $endpoint->scopes]],
        ];
        if (\in_array($method, ['GET', 'DELETE'], true)) {
            foreach ($fields as $name => $field) {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => !$field->nullable && !$field->hasDefault, 'schema' => InputSchema::field($field)];
            }
        } elseif ($fields !== []) {
            $required = array_keys(array_filter($fields, static fn (FieldPlan $f): bool => !$f->nullable && !$f->hasDefault));
            $described['requestBody'] = [
                'required' => $required !== [],
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => array_map(InputSchema::field(...), $fields),
                    'required' => array_map(strval(...), $required),
                ]]],
            ];
        }
        if ($parameters !== []) {
            $described['parameters'] = $parameters;
        }

        $described['responses'] = match ($endpoint->returns) {
            CompiledEndpoint::RETURNS_DATA => ['200' => [
                'description' => 'What the endpoint answers with.',
                'content' => ['application/json' => ['schema' => $endpoint->data === null ? ['description' => 'Any value.'] : $this->schemas->reference($endpoint->data, $endpoint->where())]],
            ]],
            CompiledEndpoint::RETURNS_NOTHING => ['204' => ['description' => 'Done.']],
            default => ['200' => [
                'description' => 'The endpoint\'s own response, of the type it says.',
                'content' => ['*/*' => ['schema' => ['description' => 'Whatever the endpoint answers with.']]],
            ]],
        } + self::refusalRefs();
        $servers = $this->serversFor($endpoint->host === null ? [] : [$endpoint->host->pattern]);
        if ($servers !== []) {
            $described['servers'] = $servers;
        }

        return $described;
    }

    /**
     * What an operation answers with: a query's return type, a batch
     * query's element, an action's ActionResult value where its docblock
     * names it.
     *
     * @return array<string, mixed>
     */
    private function answerOf(Operation $operation, string $where): array
    {
        if ($operation->kind === Operation::QUERY) {
            $class = new \ReflectionClass($operation->class);
            if (is_subclass_of($operation->class, BatchQuery::class)) {
                $element = DocBlock::elementType(DocBlock::returnType($class->getMethod('loadMany')->getDocComment()) ?? '')
                    ?? throw new CodegenException("{$operation->class}::loadMany() needs an `@return list<SomeData>` docblock so its value can be described.");

                return $this->schemas->hint($element['value'], "{$operation->class}::loadMany()", $class);
            }
            $invoke = $class->getMethod('__invoke');
            $type = $invoke->getReturnType() ?? throw new CodegenException("{$operation->class}::__invoke() needs a return type.");

            return $this->schemas->type($type, DocBlock::returnType($invoke->getDocComment()), "{$operation->class}::__invoke()", $class);
        }

        foreach (new \ReflectionClass($operation->class)->getMethods() as $method) {
            if (!$this->isAction($method, $operation->name)) {
                continue;
            }
            $returns = DocBlock::returnType($method->getDocComment());
            if ($returns !== null && preg_match('/^\\\\?(?:Tinvar\\\\Actions\\\\)?ActionResult<(.+)>$/s', trim($returns), $m) === 1) {
                return $this->schemas->hint($m[1], "{$operation->class}::{$method->getName()}()", $method->getDeclaringClass());
            }
        }

        return ['description' => "{$where} answers with its result's value, which its docblock does not name: `@return ActionResult<SomeData>` would."];
    }

    private function isAction(\ReflectionMethod $method, string $name): bool
    {
        foreach ($method->getAttributes(\Tinvar\Attributes\Action::class) as $attribute) {
            $action = $attribute->newInstance();
            if (($action->name ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function summary(Operation $operation): ?string
    {
        if ($operation->kind === Operation::QUERY) {
            return self::docSummary(new \ReflectionClass($operation->class));
        }
        foreach (new \ReflectionClass($operation->class)->getMethods() as $method) {
            if ($this->isAction($method, $operation->name)) {
                return self::docSummary($method);
            }
        }

        return null;
    }

    /**
     * The first sentence of a docblock, as one line; null for none.
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $of
     */
    private static function docSummary(\ReflectionClass|\ReflectionMethod $of): ?string
    {
        $doc = $of->getDocComment();
        if ($doc === false) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? '');
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $lines[] = $line;
        }
        // The record a sentence cites is the framework's business, not the
        // API reader's.
        $text = trim((string) preg_replace('/\s*\(ADRs? [0-9, and]+\)/', '', implode(' ', $lines)));
        if ($text === '') {
            return null;
        }
        $end = preg_match('/^(.+?[.:])(\s|$)/s', $text, $m) === 1 ? $m[1] : $text;

        return rtrim($end, ':') . (str_ends_with($end, ':') ? '.' : '');
    }

    /** @return array<string, mixed> */
    private function scalarSchema(string $type, string $where): array
    {
        if (is_subclass_of($type, \BackedEnum::class)) {
            return $this->schemas->reference($type, $where);
        }
        if (is_subclass_of($type, Model::class)) {
            return ['type' => 'string'];
        }

        return match ($type) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Where a call held to a host is made, when it is not the primary host
     * and its pattern names no parameter.
     *
     * @param list<string> $hosts
     * @return list<array{url: string}>
     */
    private function serversFor(array $hosts): array
    {
        if ($this->domains === null || $hosts === []) {
            return [];
        }
        try {
            return [['url' => $this->domains->origin($hosts[0])]];
        } catch (\Throwable) {
            // A host with a parameter -- a tenant's -- is any of many.
            return [];
        }
    }

    /**
     * The refusals by status, in order. An action's own 4xx reaches the
     * client with its status and code (CallResult::REJECTED): 402 for a
     * plan, 409 for a state, and any other -- `4XX` -- as it answered; its
     * 422 and 429 share those statuses' entries. A query answers none.
     *
     * @return array<int|string, array{'$ref': string}> by status
     */
    private static function refusalRefs(bool $action = false): array
    {
        $ref = static fn (string $name): array => ['$ref' => "#/components/responses/{$name}"];

        return array_filter([
            '401' => $ref('Unauthenticated'),
            '402' => $action ? $ref('PlanRequired') : null,
            '403' => $ref('Forbidden'),
            '404' => $ref('NotFound'),
            '409' => $action ? $ref('Conflict') : null,
            '422' => $ref('ValidationFailed'),
            '429' => $ref('TooManyRequests'),
            '4XX' => $action ? $ref('Rejected') : null,
            '500' => $ref('ServerError'),
        ], static fn (?array $r): bool => $r !== null);
    }

    /** @return array<string, array<string, mixed>> */
    private static function refusals(): array
    {
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        $challenge = ['WWW-Authenticate' => ['description' => 'Bearer realm="api", with error="invalid_token" for a token that does not hold, or error="insufficient_scope" and the scopes needed.', 'schema' => ['type' => 'string']]];

        return [
            'Unauthenticated' => ['description' => 'No API token, or one that does not hold: revoked, expired, ended by a sign-out everywhere, or another host\'s.', 'headers' => $challenge, 'content' => $error],
            'Forbidden' => ['description' => 'The token lacks a scope the call requires (`insufficient_scope`), was presented outside its tenant or panel, or the user may not do this.', 'headers' => $challenge, 'content' => $error],
            'NotFound' => ['description' => 'No such operation here, or a record the call names is not there.', 'content' => $error],
            'PlanRequired' => ['description' => 'The action needs a plan the payer is not on (`plan_required`, from #[RequiresPlan] or #[RequiresFeature]): choose a plan, or update the payment details.', 'content' => $error],
            'Conflict' => ['description' => 'The action refused for a state it is not in -- `already_subscribed`, say -- with its own code.', 'content' => $error],
            'Rejected' => ['description' => 'Any other 4xx the action answered itself, with its status and its own code; never its message.', 'content' => $error],
            'ValidationFailed' => ['description' => 'The input does not validate: each field\'s messages. Or an action\'s own 422, in the error shape with its code.', 'content' => ['application/json' => ['schema' => ['oneOf' => [['$ref' => '#/components/schemas/ValidationErrors'], ['$ref' => '#/components/schemas/Error']]]]]],
            'TooManyRequests' => [
                'description' => 'A throttle is spent: wait for Retry-After seconds. Or an action\'s own limit, with its code.',
                'headers' => [
                    'Retry-After' => ['description' => 'Seconds until a call is taken again.', 'schema' => ['type' => 'integer']],
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                ],
                'content' => $error,
            ],
            'ServerError' => ['description' => 'It failed: the digest names it in the application\'s log.', 'content' => $error],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function errorSchemas(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'properties' => ['error' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'What kind of refusal: unauthenticated, api_token_invalid, insufficient_scope, forbidden, not_found, too_many_requests, ...; or an action\'s own code with its 4xx -- plan_required, already_subscribed -- `rejected` when it gave none fit to show'],
                        'message' => ['type' => 'string', 'description' => 'The status\'s fixed reason, never the failure\'s own message.'],
                        'digest' => ['type' => 'string', 'description' => 'What the application\'s log has the failure under.'],
                    ],
                    'required' => ['code', 'message', 'digest'],
                ]],
                'required' => ['error'],
            ],
            'ValidationErrors' => [
                'type' => 'object',
                'properties' => ['errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]],
                'required' => ['errors'],
            ],
        ];
    }

    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
