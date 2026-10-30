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
 * Replaces the choices of a span with the choices of a span with the same
 * label from another corpus entry.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class CrossOver implements Mutation
{
    private Corpus $corpus;

    public function __construct(Corpus $corpus)
    {
        $this->corpus = $corpus;
    }

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $spans   = $candidate->spans();
        $entries = $this->corpus->entries();

        if ($spans === [] || $entries === []) {
            return false;
        }

        $span   = RandomElement::of($randomizer, $spans);
        $other  = Candidate::from(RandomElement::of($randomizer, $entries)->sequence());
        $donors = [];

        foreach ($other->spans() as $donor) {
            if ($donor['label'] === $span['label']) {
                $donors[] = $donor;
            }
        }

        if ($donors === []) {
            return false;
        }

        $donor = RandomElement::of($randomizer, $donors);
        $slice = $other->slice($donor['start'], $donor['end']);

        $candidate->replace($span['start'], $span['end'], $slice['choices'], $slice['maxima']);

        return true;
    }
}
