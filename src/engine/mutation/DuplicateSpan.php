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

use function array_key_exists;
use function array_slice;
use function assert;
use function count;
use function usort;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * Inserts a copy of the choices of a span right after it, for instance to
 * repeat an element of a collection or a byte of a string.
 *
 * Half of the time, the span is an element of a collection, when the choice
 * sequence has elements (see SpanKind::Element and
 * SpanKind::RequiredElement). Half of the time, the span is copied together
 * with the siblings that directly follow it, spans with the same label and
 * the same parent, so that a run of elements is repeated. The span that ends
 * a collection is never copied. This makes a collection grow by more than
 * one element at a time, which matters when only a much longer collection
 * produces a new feature.
 *
 * No choice added an element that the minimum size of a collection requires,
 * and nothing can be inserted between these elements. The copy of such an
 * element is inserted as an element that a choice added, the choice 1
 * followed by the choices of the element, and the copy of a run that ends
 * with such an element is inserted behind the last of the elements that the
 * minimum size requires. Such an element is not copied when its collection
 * cannot have another element.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DuplicateSpan implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        // the span that ends a collection is not duplicated: what follows a
        // copy of it would not be part of the collection
        $spans     = [];
        $elements  = [];
        $positions = [];

        foreach ($candidate->spans() as $span) {
            if ($candidate->endsCollection($span)) {
                continue;
            }

            // a collection that has its maximum size and whose elements are
            // all required by its minimum size cannot have another element
            if ($candidate->isRequired($span)) {
                $collection = $span['parent'] ?? '-';

                // the position is the same for all elements of a collection
                if (!array_key_exists($collection, $positions)) {
                    $positions[$collection] = $candidate->positionBehindRequiredElements($span);
                }

                if ($positions[$collection] === null) {
                    continue;
                }
            }

            $spans[] = $span;

            if ($candidate->isElement($span)) {
                $elements[] = $span;
            }
        }

        if ($spans === []) {
            return false;
        }

        if ($elements !== [] && $randomizer->getInt(0, 1) === 0) {
            $span = RandomElement::of($randomizer, $elements);
        } else {
            $span = RandomElement::of($randomizer, $spans);
        }

        $run = [$span];

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

        // the elements that the minimum size of a collection requires come
        // first: when the run starts with an element that a choice added, it
        // has no such element
        if ($candidate->isRequired($span)) {
            $this->insertAsElementsThatAChoiceAdded($candidate, $run);

            return true;
        }

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

    /**
     * Inserts a copy of each element of a run of elements of a collection
     * that starts with an element that the minimum size of the collection
     * requires. The copies are inserted right after the run when it ends
     * with an element that a choice added, and behind the last of the
     * elements that the minimum size requires otherwise.
     *
     * @param non-empty-list<array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind}> $run
     */
    private function insertAsElementsThatAChoiceAdded(Candidate $candidate, array $run): void
    {
        $last     = $run[count($run) - 1];
        $position = $last['end'];

        if ($candidate->isRequired($last)) {
            $position = $candidate->positionBehindRequiredElements($last);
        }

        // an element that the minimum size requires is only duplicated when
        // its collection has such a position
        assert($position !== null);

        foreach ($run as $element) {
            $slice = $candidate->slice($element['start'], $element['end']);

            // no choice added an element that the minimum size requires, its
            // copy is added by the choice 1
            if ($candidate->isRequired($element)) {
                $slice['choices'] = [1, ...$slice['choices']];
                $slice['maxima']  = [1, ...$slice['maxima']];
            }

            // the copies are inserted behind the run, which stays where it is
            $candidate->insert($position, $slice['choices'], $slice['maxima'], $element['label'], $element['parent'], SpanKind::Element);

            $position += count($slice['choices']);
        }
    }
}
