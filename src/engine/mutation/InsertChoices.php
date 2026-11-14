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

use function array_filter;
use function array_values;
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
 * of one of its elements is inserted. Bytes and elements are only inserted
 * where a choice decides whether there is another element, not between the
 * elements that the minimum size of a collection requires (see
 * Candidate::isDecided()), and only elements that were decided by a choice
 * are copied.
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

        // at the boundary between bytes of a string, bytes are inserted
        if ($candidate->isByteOfString($span) && $candidate->isDecided($span)) {
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
        if ($candidate->isDecided($span)) {
            $elements = array_values(array_filter($candidate->elementsBesides($span), $candidate->isDecided(...)));

            if ($elements !== []) {
                if ($candidate->endsCollection($span)) {
                    $position = $span['start'];
                }

                $element = RandomElement::of($randomizer, $elements);
                $slice   = $candidate->slice($element['start'], $element['end']);

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
