<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\UninitializedField;

/**
 * A field a Data class leaves unset, against the type generated for it.
 *
 * The encoder left an uninitialized field out of the payload, while the
 * generated type said `watts: number | null`: a component testing for null
 * found undefined, and a key the type promised was missing.
 */

use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;

final readonly class ReadingData extends Data
{
    /** Set only when a reading exists. */
    public ?int $watts;

    public function __construct(public string $unit, ?int $watts = null)
    {
        if ($watts !== null) {
            $this->watts = $watts;
        }
    }
}

it('sends an unset nullable field as null, as its type says', function () {
    expect((new TypeScriptGenerator())->generate([ReadingData::class]))->toContain('  watts: number | null;')
        ->and((new Encoder())->encode(new ReadingData('u-1')))->toBe(['watts' => null, 'unit' => 'u-1']);
});

it('sends the value of the same field once it is set', function () {
    expect((new Encoder())->encode(new ReadingData('u-1', 120)))->toBe(['watts' => 120, 'unit' => 'u-1']);
});

// The reason DataShape exists: the type lists exactly the keys the payload has.
it('generates exactly the fields the encoder writes, set or not', function () {
    $ts = (new TypeScriptGenerator())->generate([ReadingData::class]);
    preg_match('/export interface ReadingData \{(.+?)\}/s', $ts, $m);
    preg_match_all('/^\s*(\w+):/m', $m[1], $fields);

    expect($fields[1])->toBe(array_keys((new Encoder())->encode(new ReadingData('u-1'))));
});
