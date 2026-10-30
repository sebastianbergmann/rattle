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

use Random\Randomizer;

/**
 * Inserts a copy of the choices of a span right after it, for instance to
 * repeat an element of a collection.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DuplicateSpan implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $spans = $candidate->spans();

        if ($spans === []) {
            return false;
        }

        $span  = RandomElement::of($randomizer, $spans);
        $slice = $candidate->slice($span['start'], $span['end']);

        $candidate->insert($span['end'], $slice['choices'], $slice['maxima'], $span['label'], $span['parent']);

        return true;
    }
}
