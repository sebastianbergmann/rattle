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
 * Inserts a few random choices at the start or the end of a span, or at the
 * start or the end of a candidate without spans. Most of them are small,
 * like the choices that decide whether a collection has another element.
 * At the start or the end of a span that holds one byte of a binary string,
 * a few bytes are inserted as spans of the same kind, [1, b].
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
        if (isset($candidate->labelsOfBytes()[$span['label']])) {
            $byte = BytesEncoding::byte();

            // bytes after the span that ends the string would not be drawn
            if ($span['end'] - $span['start'] === 1) {
                $position = $span['start'];
            }

            for ($i = $randomizer->getInt(1, self::LARGEST_NUMBER_OF_BYTES); $i > 0; $i--) {
                $candidate->insert($position, [1, $byte->sample($randomizer, 0xFF)], [1, 0xFF], $span['label'], $span['parent']);
            }

            return true;
        }

        $candidate->insert($position, $this->randomChoices($randomizer));

        return true;
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
