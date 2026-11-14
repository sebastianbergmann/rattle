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

use function ord;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\Bytes;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * Inserts a few random choices at the start or the end of a span, or at the
 * start or the end of a candidate without spans. Most of them are small,
 * like the choices that decide whether a collection has another element.
 * At the start or the end of a span that holds one byte of a binary string,
 * a few bytes are inserted as spans of the same kind, [1, b]. At the start or
 * the end of an element of another collection (see SpanKind::Element), a copy
 * of one of its elements is inserted.
 *
 * Bytes and elements are only inserted where a choice decides whether there
 * is another element. For a span that holds an element that the minimum size
 * of its collection requires (see SpanKind::RequiredElement), nothing is
 * inserted at its start or its end: the bytes, or the copy of an element, are
 * inserted behind the last of the elements that the minimum size requires,
 * and nothing is inserted when the collection cannot have another element.
 * The copy of an element that the minimum size requires is inserted as an
 * element that a choice added, the choice 1 followed by the choices of the
 * element.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class InsertChoices implements Mutation
{
    private const int LARGEST_NUMBER_OF_CHOICES = 8;
    private const int LARGEST_NUMBER_OF_BYTES   = 4;

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $spans = $candidate->spans();

        if ($spans === []) {
            $candidate->insert($randomizer->getInt(0, 1) === 0 ? 0 : $candidate->length(), $this->randomChoices($randomizer));

            return true;
        }

        $span     = RandomElement::of($randomizer, $spans);
        $position = $randomizer->getInt(0, 1) === 0 ? $span['start'] : $span['end'];

        // nothing is inserted before or between the elements that the minimum
        // size of a collection requires, an element is inserted behind the
        // last of them instead
        if ($candidate->isRequired($span)) {
            $position = $candidate->positionBehindRequiredElements($span);

            // a collection that has its maximum size and whose elements are
            // all required by its minimum size cannot have another element
            if ($position === null) {
                return false;
            }
        }

        // at the boundary between bytes of a string, bytes are inserted
        if ($candidate->isByteOfString($span)) {
            // bytes after the span that ends the string would not be drawn
            if ($candidate->endsCollection($span)) {
                $position = $span['start'];
            }

            for ($i = $randomizer->getInt(1, self::LARGEST_NUMBER_OF_BYTES); $i > 0; $i--) {
                $candidate->insert($position, [1, $this->randomByte($randomizer)], [1, 0xFF], $span['label'], $span['parent'], SpanKind::Element);
            }

            return true;
        }

        // at the boundary between elements of another collection, a copy of
        // one of its elements is inserted, which makes the collection grow
        // by an element that its generator can generate
        if ($candidate->isDecided($span) || $candidate->isRequired($span)) {
            $elements = $candidate->elementsBesides($span);

            if ($elements !== []) {
                if ($candidate->endsCollection($span)) {
                    $position = $span['start'];
                }

                $element = RandomElement::of($randomizer, $elements);
                $slice   = $candidate->slice($element['start'], $element['end']);

                // no choice added an element that the minimum size requires,
                // its copy is added by the choice 1
                if ($candidate->isRequired($element)) {
                    $slice['choices'] = [1, ...$slice['choices']];
                    $slice['maxima']  = [1, ...$slice['maxima']];
                }

                $candidate->insert($position, $slice['choices'], $slice['maxima'], $element['label'], $element['parent'], SpanKind::Element);

                return true;
            }
        }

        $candidate->insert($position, $this->randomChoices($randomizer));

        return true;
    }

    /**
     * The value of a byte, drawn as the Bytes of sebastian/choice-sequence
     * draws the bytes of a binary string, so that the bytes that are inserted
     * are distributed like the ones of the strings that are generated.
     *
     * @return int<0, 255>
     */
    private function randomByte(Randomizer $randomizer): int
    {
        return ord(new Bytes(1, 1)->draw(ChoiceSource::random($randomizer)));
    }

    /**
     * @return list<non-negative-int>
     */
    private function randomChoices(Randomizer $randomizer): array
    {
        $choices = [];

        for ($i = $randomizer->getInt(1, self::LARGEST_NUMBER_OF_CHOICES); $i > 0; $i--) {
            $choices[] = match ($randomizer->getInt(0, 9)) {
                0, 1, 2, 3, 4 => $randomizer->getInt(0, 1),
                5, 6, 7, 8    => $randomizer->getInt(0, 0xFF),
                default       => $randomizer->getInt(0, 0xFFFF),
            };
        }

        return $choices;
    }
}
