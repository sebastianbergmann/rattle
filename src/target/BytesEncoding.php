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
use function min;
use function ord;
use function strlen;

/**
 * Converts between a binary string and the choices from which the Bytes of
 * sebastian/choice-sequence draws it when its minimum length is 0, as the
 * bytes of a bytes target and of PHPUnit's generator for binary strings are
 * drawn: each byte is the choice 1 ("another byte") followed by the value of
 * the byte, and unless the maximum length is reached, the choice 0 ends the
 * string.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class BytesEncoding
{
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
