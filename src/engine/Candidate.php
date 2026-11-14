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

use function array_fill;
use function array_slice;
use function array_splice;
use function assert;
use function count;
use function usort;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * A list of choices that is being mutated, with the maxima and the spans of
 * the choice sequence it was created from. The spans are moved along when
 * choices are deleted or inserted, so that several mutations can be applied
 * one after the other. A span whose choices are all deleted becomes empty
 * and is ignored. Inserted choices have no known maximum.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Candidate
{
    /**
     * @var list<non-negative-int>
     */
    private array $choices;

    /**
     * @var list<?non-negative-int>
     */
    private array $maxima;

    /**
     * @var list<array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind}>
     */
    private array $spans = [];

    public static function from(ChoiceSequence $sequence): self
    {
        $maxima = [];

        foreach ($sequence->choices() as $index => $choice) {
            $maxima[] = $sequence->maximumAt($index);
        }

        $candidate = new self($sequence->choices(), $maxima);

        foreach ($sequence->spans() as $span) {
            $candidate->spans[] = [
                'start'  => $span->start(),
                'end'    => $span->end(),
                'label'  => $span->label(),
                'parent' => $span->parent(),
                'kind'   => $span->kind(),
            ];
        }

        return $candidate;
    }

    /**
     * @param list<non-negative-int>  $choices
     * @param list<?non-negative-int> $maxima
     */
    private function __construct(array $choices, array $maxima)
    {
        $this->choices = $choices;
        $this->maxima  = $maxima;
    }

    /**
     * @return list<non-negative-int>
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * @return non-negative-int
     */
    public function length(): int
    {
        return count($this->choices);
    }

    /**
     * @param non-negative-int $index
     *
     * @return non-negative-int
     */
    public function choiceAt(int $index): int
    {
        assert(isset($this->choices[$index]));

        return $this->choices[$index];
    }

    /**
     * The largest choice that the choice at the index could have been, null
     * when this is not known.
     *
     * @param non-negative-int $index
     *
     * @return ?non-negative-int
     */
    public function maximumAt(int $index): ?int
    {
        return $this->maxima[$index] ?? null;
    }

    /**
     * @param non-negative-int $index
     * @param non-negative-int $choice
     */
    public function set(int $index, int $choice): void
    {
        assert(isset($this->choices[$index]));

        $this->choices[$index] = $choice;
    }

    /**
     * The spans that are not empty, with their index among all spans.
     *
     * @return array<non-negative-int, array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind}>
     */
    public function spans(): array
    {
        $spans = [];

        foreach ($this->spans as $index => $span) {
            if ($span['end'] > $span['start']) {
                $spans[$index] = $span;
            }
        }

        return $spans;
    }

    /**
     * Whether the span holds one byte of a binary string or ends it: a span
     * that is part of a span of the kind SpanKind::Bytes and is of the kind
     * SpanKind::RequiredElement, [b], for a byte that the minimum length of
     * the string requires, or of the kind SpanKind::Element, [1, b] for any
     * other byte and [0] for the end of the string.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function isByteOfString(array $span): bool
    {
        return $this->isPartOf($span, SpanKind::Bytes);
    }

    /**
     * Whether the span holds one character of a string of Unicode characters
     * or ends it: a span that is part of a span of the kind
     * SpanKind::Characters and is of the kind SpanKind::RequiredElement, [c],
     * for a character that the minimum length of the string requires, or of
     * the kind SpanKind::Element, [1, c] for any other character and [0] for
     * the end of the string, where c is the index of the code point of the
     * character in the order of CodePoints.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function isCharacterOfString(array $span): bool
    {
        return $this->isPartOf($span, SpanKind::Characters);
    }

    /**
     * Whether a choice decided that the collection has the element that the
     * span holds, or that it has no other element: a span of the kind
     * SpanKind::Element, which starts with the choice 1 that adds the
     * element, or consists of the choice 0 that ends the collection. An
     * element can be inserted before such a span.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function isDecided(array $span): bool
    {
        return $span['kind'] === SpanKind::Element;
    }

    /**
     * Whether the span holds an element that the minimum size of its
     * collection requires: a span of the kind SpanKind::RequiredElement,
     * which consists of the choices of the element. These elements come
     * first. No choice decided that the collection has one of them, so that
     * no element can be inserted before them, even when the first choice of
     * the element is a 1 whose maximum is 1, as that of a boolean.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function isRequired(array $span): bool
    {
        return $span['kind'] === SpanKind::RequiredElement;
    }

    /**
     * Whether the span holds an element of a collection: a span of the kind
     * SpanKind::RequiredElement, or a span of the kind SpanKind::Element that
     * does not end the collection. The generators of PHPUnit mark the
     * elements of lists, arrays, strings, and binary strings this way, and
     * Input marks the bytes of binary strings.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function isElement(array $span): bool
    {
        return $this->isRequired($span) || ($this->isDecided($span) && !$this->endsCollection($span));
    }

    /**
     * Whether the span ends a collection: the last span of the kind
     * SpanKind::Element of its parent that consists of the choice 0 that
     * decided that there is no other element. An element that the minimum
     * size of the collection requires does not end it, even when it consists
     * of a 0 whose maximum is 1.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    public function endsCollection(array $span): bool
    {
        if ($span['kind'] !== SpanKind::Element ||
            $span['end'] - $span['start'] !== 1 ||
            $this->choiceAt($span['start']) !== 0 ||
            $this->maximumAt($span['start']) !== 1) {
            return false;
        }

        foreach ($this->spans() as $other) {
            if ($other['kind'] === SpanKind::Element &&
                $other['parent'] === $span['parent'] &&
                $other['start'] >= $span['end']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The elements of the collection that the span is part of, in the order
     * in which they occur.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     *
     * @return list<array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind}>
     */
    public function elementsBesides(array $span): array
    {
        $elements = [];

        foreach ($this->spans() as $other) {
            if ($other['parent'] === $span['parent'] && $this->isElement($other)) {
                $elements[] = $other;
            }
        }

        usort($elements, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $elements;
    }

    /**
     * @param non-negative-int $start
     * @param non-negative-int $end
     *
     * @return array{choices: list<non-negative-int>, maxima: list<?non-negative-int>}
     */
    public function slice(int $start, int $end): array
    {
        return [
            'choices' => array_slice($this->choices, $start, $end - $start),
            'maxima'  => array_slice($this->maxima, $start, $end - $start),
        ];
    }

    /**
     * @param non-negative-int $start
     * @param non-negative-int $length
     */
    public function delete(int $start, int $length): void
    {
        $end = $start + $length;

        array_splice($this->choices, $start, $length);
        array_splice($this->maxima, $start, $length);

        $map = static fn (int $position): int => match (true) {
            $position <= $start => $position,
            $position >= $end   => $position - $length,
            default             => $start,
        };

        foreach ($this->spans as $index => $span) {
            $newStart = $map($span['start']);
            $newEnd   = $map($span['end']);

            assert($newStart >= 0 && $newEnd >= 0);

            $this->spans[$index]['start'] = $newStart;
            $this->spans[$index]['end']   = $newEnd;
        }
    }

    /**
     * Inserts choices before the choice at the position. Spans that start at
     * the position are moved behind the inserted choices, spans that end at
     * the position are not extended. When a label is given, the inserted
     * choices form a span with that label, parent, and kind.
     *
     * @param non-negative-int         $position
     * @param list<non-negative-int>   $choices
     * @param ?list<?non-negative-int> $maxima
     * @param ?non-empty-string        $label
     * @param ?non-negative-int        $parent
     */
    public function insert(int $position, array $choices, ?array $maxima = null, ?string $label = null, ?int $parent = null, ?SpanKind $kind = null): void
    {
        $length = count($choices);

        if ($length === 0) {
            return;
        }

        $maxima ??= array_fill(0, $length, null);

        array_splice($this->choices, $position, 0, $choices);
        array_splice($this->maxima, $position, 0, $maxima);

        foreach ($this->spans as $index => $span) {
            $start = $span['start'];
            $end   = $span['end'];

            if ($start === $end) {
                // an empty span stays empty and moves with what follows it
                if ($start >= $position) {
                    $start += $length;
                    $end   += $length;
                }
            } else {
                if ($start >= $position) {
                    $start += $length;
                }

                if ($end > $position) {
                    $end += $length;
                }
            }

            $this->spans[$index]['start'] = $start;
            $this->spans[$index]['end']   = $end;
        }

        if ($label !== null) {
            $this->spans[] = ['start' => $position, 'end' => $position + $length, 'label' => $label, 'parent' => $parent, 'kind' => $kind];
        }
    }

    /**
     * Adds a span, for instance for choices that were inserted.
     *
     * @param non-negative-int  $start
     * @param non-negative-int  $end
     * @param non-empty-string  $label
     * @param ?non-negative-int $parent
     */
    public function addSpan(int $start, int $end, string $label, ?int $parent, ?SpanKind $kind = null): void
    {
        assert($start <= $end && $end <= count($this->choices));

        $this->spans[] = ['start' => $start, 'end' => $end, 'label' => $label, 'parent' => $parent, 'kind' => $kind];
    }

    /**
     * Replaces the choices of a range with other choices. Spans that contain
     * the range contain what replaces it, spans within the range become
     * empty.
     *
     * @param non-negative-int        $start
     * @param non-negative-int        $end
     * @param list<non-negative-int>  $choices
     * @param list<?non-negative-int> $maxima
     */
    public function replace(int $start, int $end, array $choices, array $maxima): void
    {
        $length = count($choices);
        $delta  = $length - ($end - $start);

        array_splice($this->choices, $start, $end - $start, $choices);
        array_splice($this->maxima, $start, $end - $start, $maxima);

        foreach ($this->spans as $index => $span) {
            if ($span['start'] <= $start && $span['end'] >= $end) {
                $newStart = $span['start'];
                $newEnd   = $span['end'] + $delta;
            } else {
                $newStart = match (true) {
                    $span['start'] <= $start => $span['start'],
                    $span['start'] >= $end   => $span['start'] + $delta,
                    default                  => $start,
                };

                $newEnd = match (true) {
                    $span['end'] <= $start => $span['end'],
                    $span['end'] >= $end   => $span['end'] + $delta,
                    default                => $start,
                };
            }

            assert($newStart >= 0 && $newEnd >= $newStart);

            $this->spans[$index]['start'] = $newStart;
            $this->spans[$index]['end']   = $newEnd;
        }
    }

    /**
     * Cuts off the choices from the position on.
     *
     * @param non-negative-int $position
     */
    public function truncate(int $position): void
    {
        $length = count($this->choices) - $position;

        if ($length > 0) {
            $this->delete($position, $length);
        }
    }

    /**
     * Whether the span is one of the spans that a collection of the given
     * kind consists of.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $span
     */
    private function isPartOf(array $span, SpanKind $collection): bool
    {
        if ((!$this->isDecided($span) && !$this->isRequired($span)) || $span['parent'] === null) {
            return false;
        }

        return ($this->spans[$span['parent']]['kind'] ?? null) === $collection;
    }
}
