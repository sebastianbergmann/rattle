<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle;

use function assert;
use Random\Randomizer;

/**
 * Deletes the choices of a span, for instance an element of a collection.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DeleteSpan implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $spans = $candidate->spans();

        if ($spans === []) {
            return false;
        }

        $span = RandomElement::of($randomizer, $spans);

        $length = $span['end'] - $span['start'];

        assert($length > 0);

        $candidate->delete($span['start'], $length);

        return true;
    }
}
