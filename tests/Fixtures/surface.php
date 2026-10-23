<?php

declare(strict_types=1);

// Fixtures for CodegenSurfaceTest: every shape the generator accepts or
// refuses, in a namespace of their own so short-name resolution is real.

namespace Tinvar\Tests\Codegen\Fixtures {

    use Tinvar\Actions\ActionResult;
    use Tinvar\Attributes\Action;
    use Tinvar\Attributes\Layout;
    use Tinvar\Attributes\Page;
    use Tinvar\Attributes\Query;
    use Tinvar\Data\Data;
    use Tinvar\Validation\FormRequest;

    final readonly class CgSite extends Data
    {
        public function __construct(public string $id) {}
    }

    /** Short names in docblocks, resolved against this namespace. */
    final readonly class CgPage extends Data
    {
        /**
         * @param list<list<CgSite>>           $grid
         * @param array<int, CgSite>           $byPosition
         * @param array<array-key, string>     $labels
         * @param CgSite[]                     $legacy
         * @param non-empty-list<int>          $counts
         * @param array<string, list<number>>  $series
         * @param list<true>                   $flags
         * @param list<mixed>                  $anything
         * @param list<null>                   $nothing
         * @param array<CgSite>                $plain
         */
        public function __construct(
            public array $grid,
            public array $byPosition,
            public array $labels,
            public array $legacy,
            public array $counts,
            public array $series,
            public array $flags,
            public array $anything,
            public array $nothing,
            public array $plain,
            public string|int $either,
            public mixed $whatever,
            public ?CgSite $maybe,
            /** @var list<CgSite>|null */
            public ?array $optional,
        ) {}
    }

    final readonly class CgIntersection extends Data
    {
        public function __construct(public \Countable&\Traversable $both) {}
    }

    final readonly class CgLazyNoHint extends Data
    {
        public function __construct(public \Tinvar\Data\Lazy $later) {}
    }

    final readonly class CgUnknownName extends Data
    {
        /** @param list<NoSuchThing> $things */
        public function __construct(public array $things) {}
    }

    #[Query(name: 'sites.find', args: ['site' => CgSite::class])]
    final class FindSite
    {
        /** @return list<CgSite> */
        public function loadMany(array $calls): array
        {
            return [];
        }
    }

    #[Query]
    final class SearchSites
    {
        public function __invoke(string $term, int $limit, $untyped): CgSite
        {
            return new CgSite('x');
        }
    }

    #[Query(name: 'ping')]
    final class Ping
    {
        public function __invoke(): bool
        {
            return true;
        }
    }

    final class NotAQuery
    {
        public function __invoke(): bool
        {
            return true;
        }
    }

    #[Query(name: 'sites.untypedReturn')]
    final class UntypedReturn
    {
        public function __invoke()
        {
            return null;
        }
    }

    #[Query(name: 'sites.undocumented')]
    final class UndocumentedBatch
    {
        public function loadMany(array $calls): array
        {
            return [];
        }
    }

    #[Layout('/sites', view: 'sites/layout')]
    final class SitesLayout
    {
        public function __invoke(): CgSite
        {
            return new CgSite('l');
        }
    }

    #[Page('/sites/{site}/racks/{rack}', view: 'sites/rack', name: 'sites.rack')]
    final class RackPage
    {
        public function __invoke(): CgPage
        {
            throw new \LogicException('never called by codegen');
        }
    }

    // No loader: still has props, with null data.
    #[Page('/sites/about', view: 'sites/about')]
    final class AboutPage {}

    // A builtin return is not a Data class: no data type to emit.
    #[Page('/sites/raw', view: 'sites/raw', name: 'sites.raw')]
    final class RawPage
    {
        public function __invoke(): array
        {
            return [];
        }
    }

    final readonly class RenameSite extends FormRequest
    {
        public function __construct(public string $name, public ?int $priority) {}
    }

    final class SiteActions
    {
        #[Action, \Tinvar\Attributes\Authorize('update', 'site')]
        public function rename(\Tinvar\Tests\Orm\Fixtures\Site $site, RenameSite $request): ActionResult
        {
            return ActionResult::ok();
        }
    }
}

namespace Tinvar\Tests\Codegen\Fixtures\Elsewhere {
    final readonly class CgSite extends \Tinvar\Data\Data
    {
        public function __construct(public int $other) {}
    }
}
