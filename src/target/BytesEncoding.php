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

use function chr;
use function hash;
use function implode;
use function min;
use function ord;
use function strlen;
use SebastianBergmann\ChoiceSequence\BernoulliDistribution;
use SebastianBergmann\ChoiceSequence\WeightedRangesDistribution;

/**
 * How a binary string is drawn from choices, in the same way as PHPUnit's
 * BytesGenerator with a minimum length of 0 draws it: the string is a span,
 * and each byte is a span of its own that consists of the choice 1 ("another
 * byte") followed by the value of the byte. Unless the maximum length is
 * reached, the choice 0 ends the string, also in a span of its own.
 *
 * The labels of the spans are computed as PHPUnit computes them, so that the
 * choice sequences of a bytes target and of PHPUnit's BytesGenerator have the
 * same structure.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class BytesEncoding
{
    public const float PROBABILITY_OF_ANOTHER_BYTE = 0.8;

    /**
     * Returns a label that identifies how choices are drawn for a span, in the
     * same way as PHPUnit's AbstractGenerator::labelOf().
     *
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    public static function labelOf(string $name, string ...$components): string
    {
        return hash('xxh3', $name . '(' . implode(',', $components) . ')');
    }

    /**
     * @param non-negative-int $maximumLength
     *
     * @return non-empty-string
     */
    public static function labelOfBytes(int $maximumLength): string
    {
        return self::labelOf('bytes', '0', (string) $maximumLength);
    }

    /**
     * @param non-negative-int $maximumLength
     *
     * @return non-empty-string
     */
    public static function labelOfByte(int $maximumLength): string
    {
        return self::labelOf('element', self::labelOfBytes($maximumLength));
    }

    public static function anotherByte(): BernoulliDistribution
    {
        return new BernoulliDistribution(self::PROBABILITY_OF_ANOTHER_BYTE);
    }

    /**
     * The distribution of the value of a byte, the same as in PHPUnit's
     * BytesGenerator: printable characters are drawn most often, and 0x00
     * and 0xFF have a significant probability.
     */
    public static function byte(): WeightedRangesDistribution
    {
        return new WeightedRangesDistribution(
            [
                ['weight' => 0.5, 'minimum' => 0x20, 'maximum' => 0x7E],
                ['weight' => 0.1, 'minimum' => 0x00, 'maximum' => 0x00],
                ['weight' => 0.1, 'minimum' => 0xFF, 'maximum' => 0xFF],
                ['weight' => 0.3, 'minimum' => 0x00, 'maximum' => 0xFF],
            ],
        );
    }

    /**
     * Returns the choices from which the string is drawn: [1, b0, 1, b1, …, 0].
     * A string that is longer than the maximum length is cut off.
     *
     * @param non-negative-int $maximumLength
     *
     * @return list<non-negative-int>
     */
    public static function choicesFor(string $bytes, int $maximumLength): array
    {
        $length  = min(strlen($bytes), $maximumLength);
        $choices = [];

        for ($i = 0; $i < $length; $i++) {
            $choices[] = 1;
            $choices[] = ord($bytes[$i]);
        }

        if ($length < $maximumLength) {
            $choices[] = 0;
        }

        return $choices;
    }

    /**
     * Returns the string that is drawn when the choices are replayed: a
     * choice that exceeds its range is clamped, and the choices beyond the
     * end of the list are 0.
     *
     * @param list<non-negative-int> $choices
     * @param non-negative-int       $maximumLength
     */
    public static function bytesFor(array $choices, int $maximumLength): string
    {
        $bytes = '';
        $index = 0;

        while (strlen($bytes) < $maximumLength) {
            if (($choices[$index++] ?? 0) === 0) {
                break;
            }

            $bytes .= chr(min($choices[$index++] ?? 0, 0xFF));
        }

        return $bytes;
    }
}
