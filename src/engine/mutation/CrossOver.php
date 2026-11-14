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
 * The elements that the minimum size of a collection requires (see
 * SpanKind::RequiredElement) and the elements that a choice added (see
 * SpanKind::Element) have the same label. When the choices of one replace
 * the choices of the other, the choice 1 that added an element is left out
 * or put in front, so that the choices of an element replace the choices of
 * an element. The span that ends a collection has no choices of an element,
 * and does not replace an element that the minimum size requires.
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
        $other  = RandomElement::of($randomizer, $entries)->candidate();
        $donors = [];

        foreach ($other->spans() as $donor) {
            if ($donor['label'] !== $span['label']) {
                continue;
            }

            // the span that ends a collection has no choices of an element
            if ($candidate->isRequired($span) && $other->endsCollection($donor)) {
                continue;
            }

            $donors[] = $donor;
        }

        if ($donors === []) {
            return false;
        }

        $donor = RandomElement::of($randomizer, $donors);
        $slice = $other->slice($donor['start'], $donor['end']);

        // no choice added an element that the minimum size of a collection
        // requires: the choice that added an element is not part of what
        // replaces such an element, and is put in front of the choices of
        // such an element when they replace an element that a choice added
        // or the span that ends a collection
        if ($candidate->isRequired($span) && $other->isDecided($donor)) {
            $slice = $other->slice($donor['start'] + 1, $donor['end']);
        } elseif ($candidate->isDecided($span) && $other->isRequired($donor)) {
            $slice['choices'] = [1, ...$slice['choices']];
            $slice['maxima']  = [1, ...$slice['maxima']];
        }

        $candidate->replace($span['start'], $span['end'], $slice['choices'], $slice['maxima']);

        return true;
    }
}
