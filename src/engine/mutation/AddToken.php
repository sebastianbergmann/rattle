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
use function count;
use function mb_check_encoding;
use function mb_ord;
use function mb_str_split;
use function ord;
use function strlen;
use function usort;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * Adds a token of the dictionary to a string in one of two ways that are
 * chosen at random:
 *
 * - the token is inserted at the boundary between two elements where a choice
 *   decides whether there is another element: before a span that holds one
 *   element, [1, …], or before the span that ends the string, [0]
 * - the token overwrites the values of the elements from one of them on, and
 *   the elements that it does not overwrite are added before the span that
 *   ends the string
 *
 * The elements that the minimum length of the string requires come first and
 * hold only their value. A token is never inserted before them, but it can
 * overwrite them, which is how a string with a minimum length can become the
 * token.
 *
 * Into a binary string, the token is added as the choices of its bytes. Into
 * a string of Unicode characters, a token that is valid UTF-8 is added as the
 * indices of the code points of its characters in the order of CodePoints.
 *
 * The spans of the bytes and characters of a string are recognised by their
 * kinds, so that tokens can be added to every string that a structured
 * target or a property draws, as long as it is marked.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class AddToken implements Mutation
{
    private Dictionary $dictionary;

    public function __construct(Dictionary $dictionary)
    {
        $this->dictionary = $dictionary;
    }

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        // the dictionary can be empty before tokens are found in the code
        // that is instrumented, unless it has sentinels
        if ($this->dictionary->isEmpty()) {
            return false;
        }

        $overwrite = $randomizer->getInt(0, 1) === 1;
        $positions = [];

        foreach ($candidate->spans() as $span) {
            if (!$candidate->isByteOfString($span) && !$candidate->isCharacterOfString($span)) {
                continue;
            }

            // a token overwrites the elements from any of them on, but it is
            // only inserted where a choice decides whether there is another
            // element
            if ($overwrite || $candidate->isDecided($span)) {
                $positions[] = $span;
            }
        }

        if ($positions === []) {
            return false;
        }

        $position = RandomElement::of($randomizer, $positions);
        $token    = $this->dictionary->randomToken($randomizer);

        // no sentinel was drawn, and there are no other tokens
        if ($token === null) {
            return false;
        }

        if ($candidate->isCharacterOfString($position)) {
            $values  = self::indicesOfCodePointsOf($token);
            $maximum = CodePoints::LARGEST_INDEX;

            // a token that is not valid UTF-8 cannot be added to a string of
            // Unicode characters
            if ($values === null) {
                return false;
            }
        } else {
            $values  = [];
            $maximum = 0xFF;

            for ($i = 0; $i < strlen($token); $i++) {
                $values[] = ord($token[$i]);
            }
        }

        if (!$overwrite) {
            $this->insert($candidate, $position, $position['start'], $values, $maximum);

            return true;
        }

        $elements = $this->elementsFrom($candidate, $position);
        $next     = 0;

        // the value of an element is its last choice, after the choice that
        // decided that there is another element, if there is one
        while (isset($values[$next], $elements[$next]) && !$candidate->endsCollection($elements[$next])) {
            $value = $elements[$next]['end'] - 1;

            // the spans of a candidate are not empty
            assert($value >= 0);

            $candidate->set($value, $values[$next]);

            $next++;
        }

        // the values that did not overwrite an element are added before the
        // span that ends the string; a string that has its maximum length
        // does not end with such a span, and no element is added to it
        if (isset($values[$next], $elements[$next])) {
            $rest = [];

            for ($i = $next; $i < count($values); $i++) {
                $rest[] = $values[$i];
            }

            $this->insert($candidate, $position, $elements[$next]['start'], $rest, $maximum);
        }

        return true;
    }

    /**
     * Inserts each value as an element of its own, like the ones that were
     * drawn, [1, v].
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $sibling
     * @param non-negative-int                                                                                                           $start
     * @param list<non-negative-int>                                                                                                     $values
     * @param positive-int                                                                                                               $maximum
     */
    private function insert(Candidate $candidate, array $sibling, int $start, array $values, int $maximum): void
    {
        foreach ($values as $i => $value) {
            $candidate->insert(
                $start + 2 * $i,
                [1, $value],
                [1, $maximum],
                $sibling['label'],
                $sibling['parent'],
                SpanKind::Element,
            );
        }
    }

    /**
     * The element and the elements of the same string after it, including the
     * span that ends the string, in order.
     *
     * @param array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind} $element
     *
     * @return list<array{start: non-negative-int, end: non-negative-int, label: non-empty-string, parent: ?non-negative-int, kind: ?SpanKind}>
     */
    private function elementsFrom(Candidate $candidate, array $element): array
    {
        $elements = [];

        foreach ($candidate->spans() as $span) {
            if ($span['kind'] === SpanKind::Element && $span['parent'] === $element['parent'] && $span['start'] >= $element['start']) {
                $elements[] = $span;
            }
        }

        usort($elements, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $elements;
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
