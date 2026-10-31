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

use function count;
use Random\Randomizer;

/**
 * Swaps the choices of two spans that do not overlap and have the same label
 * and the same parent, for instance two elements of a collection. The span
 * that ends a collection is not swapped.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class SwapSpans implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $groups = [];

        foreach ($candidate->spans() as $span) {
            // the span that ends a collection stays at its end
            if (!$candidate->endsCollection($span)) {
                $groups[$span['label'] . '/' . ($span['parent'] ?? '-')][] = $span;
            }
        }

        $pairs = [];

        foreach ($groups as $spans) {
            for ($i = 0; $i < count($spans); $i++) {
                for ($j = $i + 1; $j < count($spans); $j++) {
                    if ($spans[$i]['end'] <= $spans[$j]['start']) {
                        $pairs[] = [$spans[$i], $spans[$j]];
                    } elseif ($spans[$j]['end'] <= $spans[$i]['start']) {
                        $pairs[] = [$spans[$j], $spans[$i]];
                    }
                }
            }
        }

        if ($pairs === []) {
            return false;
        }

        [$first, $second] = RandomElement::of($randomizer, $pairs);

        $firstSlice  = $candidate->slice($first['start'], $first['end']);
        $secondSlice = $candidate->slice($second['start'], $second['end']);

        // the later span is replaced first, so that the earlier one stays where it is
        $candidate->replace($second['start'], $second['end'], $firstSlice['choices'], $firstSlice['maxima']);
        $candidate->replace($first['start'], $first['end'], $secondSlice['choices'], $secondSlice['maxima']);

        return true;
    }
}
