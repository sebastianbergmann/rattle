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

use SebastianBergmann\ChoiceSequence\Bytes;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

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
        return new Bytes(0, $maximumLength)->choicesFor($bytes);
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
        // each byte is drawn from two choices, and the choice that ends the
        // string is another one
        return new Bytes(0, $maximumLength)->draw(ChoiceSource::replay(new ChoiceSequence($choices), 2 * $maximumLength + 1));
    }
}
