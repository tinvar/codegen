<?php

declare(strict_types=1);

// Fixtures for TypeResolutionTest: names a docblock reaches by `use`, from a
// namespace other than the class's own, and docblock tags with descriptions.

namespace Tinvar\Tests\Codegen\Resolution\Elsewhere {

    final readonly class TagData extends \Tinvar\Data\Data
    {
        public function __construct(public string $tag) {}
    }

    final readonly class NoteData extends \Tinvar\Data\Data
    {
        public function __construct(public string $note) {}
    }
}

namespace Tinvar\Tests\Codegen\Resolved\Sub\Deeper {

    final readonly class LabelData extends \Tinvar\Data\Data
    {
        public function __construct(public string $label) {}
    }
}

namespace Tinvar\Tests\Codegen\Resolution {

    use Tinvar\Data\Data;
    use Tinvar\Tests\Codegen\Resolution\Elsewhere\TagData;
    use Tinvar\Tests\Codegen\Resolution\Elsewhere\NoteData as Remark;
    use Tinvar\Tests\Codegen\Resolved\Sub;
    use Tinvar\Tests\Codegen\Resolution\Elsewhere\{TagData as GroupedTag, NoteData};

    use function strlen;

    /** Short names a docblock can only resolve through this file's imports. */
    final readonly class TaggedData extends Data
    {
        /** Uses each import in code too, so the formatter keeps them. */
        private const array IMPORTED = [Sub\Deeper\LabelData::class, GroupedTag::class, NoteData::class];

        /**
         * @param list<TagData>          $tags
         * @param list<Remark>           $remarks
         * @param list<Sub\Deeper\LabelData> $labels
         */
        public function __construct(
            public array $tags,
            public array $remarks,
            public array $labels,
        ) {}

        public static function imported(): int
        {
            return strlen(implode(',', self::IMPORTED));
        }
    }

    final readonly class LiteralData extends Data
    {
        public function __construct(
            public string|false $label,
            public true $always,
            public ?false $never,
        ) {}
    }

    final readonly class DescribedData extends Data
    {
        /** @var list<TagData> the tags on this page, newest first */
        public array $tags;

        /** @var array<string, int> counts by tag */
        public array $counts;

        public function __construct(
            /** @var list<TagData>|null tags, if any were asked for */
            public ?array $maybe = null,
        ) {
            $this->tags = [];
            $this->counts = [];
        }
    }
}
