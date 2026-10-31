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

use function array_slice;
use function assert;
use function count;
use function usort;
use Random\Randomizer;

/**
 * Inserts a copy of the choices of a span right after it, for instance to
 * repeat an element of a collection or a byte of a string.
 *
 * Half of the time, the span is copied together with the siblings that
 * directly follow it, spans with the same label and the same parent, so that
 * a run of elements is repeated. This makes a collection grow by more than
 * one element at a time, which matters when only a much longer collection
 * produces a new feature.
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

        $span = RandomElement::of($randomizer, $spans);
        $run  = [$span];

        if ($randomizer->getInt(0, 1) === 1) {
            $siblings = [];

            foreach ($spans as $sibling) {
                if ($sibling['label'] === $span['label'] && $sibling['parent'] === $span['parent']) {
                    $siblings[] = $sibling;
                }
            }

            usort($siblings, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

            foreach ($siblings as $sibling) {
                $last = $run[count($run) - 1];

                if ($sibling['start'] === $last['end']) {
                    $run[] = $sibling;
                }
            }

            $run = array_slice($run, 0, $randomizer->getInt(1, count($run)));
        }

        $start = $span['start'];
        $end   = $run[count($run) - 1]['end'];
        $slice = $candidate->slice($start, $end);

        $candidate->insert($end, $slice['choices'], $slice['maxima']);

        // the copied spans start where the original run ends
        foreach ($run as $copied) {
            $offset = $end - $start;

            assert($offset >= 0);

            $candidate->addSpan($copied['start'] + $offset, $copied['end'] + $offset, $copied['label'], $copied['parent'], $copied['kind']);
        }

        return true;
    }
}
