<?php

declare(strict_types=1);

namespace Tinvar\Codegen\OpenApi;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Api\ApiCalls;
use Tinvar\Attributes\AcceptsTenantTokens;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Calls\Operations;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Endpoints\CompiledEndpoint;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\Domains;

/**
 * `tinvar openapi [--out=FILE] [--check]`: the application's public API as
 * an OpenAPI 3.1 document (ADR 0159), read from what it declares -- every
 * action, query and endpoint marked #[RequiresScope] -- with nothing run
 * and no database opened.
 *
 * Printed unless --out names a file; with --check it writes nothing, and
 * fails when the file is not what the PHP describes now, naming it. `tinvar
 * build` writes it into the build as `openapi.json`, when there is an API
 * to describe.
 */
final readonly class OpenApiCommand
{
    /**
     * @param list<class-string> $actions
     * @param list<class-string> $queries
     * @param list<class-string> $endpoints
     */
    public function __construct(
        private string $title,
        private array $actions = [],
        private array $queries = [],
        private array $endpoints = [],
        private ?Domains $domains = null,
        private ?PanelRegistry $panels = null,
        private string $version = '1.0.0',
    ) {}

    /** The command for what an application registers, as its Kernel's classes() says (ADR 0146). */
    public static function of(string $title, DiscoveredClasses $classes, ?Domains $domains = null, ?PanelRegistry $panels = null, string $version = '1.0.0'): self
    {
        return new self($title, $classes->actions, $classes->queries, $classes->endpoints, $domains, $panels, $version);
    }

    /** Whether the application has any API to describe. */
    public function describesAny(): bool
    {
        [$operations, $endpoints] = $this->declared();

        return $operations !== [] || $endpoints !== [];
    }

    public function document(): OpenApiDocument
    {
        [$operations, $endpoints, $tenantTokens] = $this->declared();

        return new OpenApiDocument(
            array_map(static fn (array $entry): array => [$entry[0], $entry[1]->scopes], $operations),
            $endpoints,
            $this->title,
            $this->version,
            $this->domains,
            $tenantTokens,
        );
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $out = null;
        $check = false;
        $ifAny = false;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--out=')) {
                $out = substr($argument, 6);
            } elseif ($argument === '--check') {
                $check = true;
            } elseif ($argument === '--if-any') {
                $ifAny = true;
            } else {
                fwrite(STDERR, "Unknown argument '{$argument}'. Usage: tinvar openapi [--out=FILE] [--check] [--if-any]\n");

                return 64;
            }
        }
        if ($check && $out === null) {
            fwrite(STDERR, "--check compares the document with a file: name it with --out=FILE.\n");

            return 64;
        }
        if ($ifAny && !$this->describesAny()) {
            echo "No API to describe: nothing is marked #[RequiresScope].\n";

            return 0;
        }

        $json = $this->document()->toJson();
        if ($out === null) {
            echo $json;

            return 0;
        }
        if ($check) {
            if (is_file($out) && file_get_contents($out) === $json) {
                echo "The OpenAPI document matches the PHP.\n";

                return 0;
            }
            fwrite(STDERR, "{$out} is not what the PHP describes now; run tinvar openapi --out={$out}.\n");

            return 1;
        }
        if (!is_dir(\dirname($out))) {
            mkdir(\dirname($out), 0o755, true);
        }
        file_put_contents($out, $json);
        printf("wrote %s (%d bytes)\n", realpath($out) ?: $out, \strlen($json));

        return 0;
    }

    /**
     * What the application exposes: its operations, its endpoints, and the
     * names of the operations that accept tenant tokens (ADR 0193).
     *
     * @return array{list<array{\Tinvar\Calls\Operation, RequiresScope}>, list<CompiledEndpoint>, list<string>}
     */
    private function declared(): array
    {
        $actions = new ActionRegistry();
        $actions->registerAll($this->actions);
        $queries = new QueryRegistry();
        $queries->registerAll($this->queries);
        $endpoints = new EndpointRegistry();
        $endpoints->registerAll($this->endpoints);

        ApiCalls::refuseOnActionHolders($actions);
        $operations = Operations::described(RequiresScope::class, $actions, $queries, $this->panels);

        return [
            $operations,
            array_values(array_filter($endpoints->all(), static fn (CompiledEndpoint $e): bool => $e->takesApiTokens())),
            ApiCalls::acceptingTenantTokens(Operations::described(AcceptsTenantTokens::class, $actions, $queries, $this->panels), $operations, $actions),
        ];
    }
}
