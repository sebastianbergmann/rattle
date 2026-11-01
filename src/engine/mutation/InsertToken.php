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

use function assert;
use function mb_check_encoding;
use function mb_ord;
use function mb_str_split;
use function ord;
use function strlen;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * Inserts a token of the dictionary into a string, at the boundary between
 * two of its elements where a choice decides whether there is another
 * element: before a span that holds one element, [1, …], or before the span
 * that ends the string, [0]. The elements that the minimum length of the
 * string requires come first and hold only their value, and no token is
 * inserted before them.
 *
 * Into a binary string, the token is inserted as the choices of its bytes,
 * [1, b0, 1, b1, …]. Into a string of Unicode characters, a token that is
 * valid UTF-8 is inserted as the indices of the code points of its
 * characters in the order of CodePoints, [1, c0, 1, c1, …].
 *
 * The spans of the bytes and characters of a string are recognised by their
 * kinds, so that tokens can be inserted into every string that a structured
 * target or a property draws, as long as it is marked.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class InsertToken implements Mutation
{
    private Dictionary $dictionary;

    public function __construct(Dictionary $dictionary)
    {
        $this->dictionary = $dictionary;
    }

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        // the dictionary can be empty before tokens are found in the code
        // that is instrumented
        if ($this->dictionary->isEmpty()) {
            return false;
        }

        $positions = [];

        foreach ($candidate->spans() as $span) {
            if (($candidate->isByteOfString($span) || $candidate->isCharacterOfString($span)) && $candidate->isDecided($span)) {
                $positions[] = $span;
            }
        }

        if ($positions === []) {
            return false;
        }

        $position = RandomElement::of($randomizer, $positions);
        $token    = $this->dictionary->randomToken($randomizer);

        if ($candidate->isCharacterOfString($position)) {
            $elements = self::indicesOfCodePointsOf($token);
            $maximum  = CodePoints::LARGEST_INDEX;

            // a token that is not valid UTF-8 cannot be inserted into a
            // string of Unicode characters
            if ($elements === null) {
                return false;
            }
        } else {
            $elements = [];
            $maximum  = 0xFF;

            for ($i = 0; $i < strlen($token); $i++) {
                $elements[] = ord($token[$i]);
            }
        }

        // each byte or character is inserted as a span of its own, like the
        // ones that were drawn
        foreach ($elements as $i => $element) {
            $candidate->insert(
                $position['start'] + 2 * $i,
                [1, $element],
                [1, $maximum],
                $position['label'],
                $position['parent'],
                SpanKind::Element,
            );
        }

        return true;
    }

    /**
     * The indices of the code points of the characters of a string in the
     * order of CodePoints, or null when the string is not valid UTF-8.
     *
     * @return ?list<int<0, CodePoints::LARGEST_INDEX>>
     */
    private static function indicesOfCodePointsOf(string $token): ?array
    {
        if (!mb_check_encoding($token, 'UTF-8')) {
            return null;
        }

        $indices = [];

        foreach (mb_str_split($token, 1, 'UTF-8') as $character) {
            $codePoint = mb_ord($character, 'UTF-8');

            assert($codePoint !== false && $codePoint >= 0 && $codePoint <= 0x10FFFF);

            $indices[] = CodePoints::index($codePoint);
        }

        return $indices;
    }
}
