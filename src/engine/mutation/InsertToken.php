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
use function strlen;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * Inserts a token of the dictionary into a binary string, at the boundary
 * between two of its bytes: before a span that holds one byte, [1, b], or
 * before the span that ends the string, [0]. The token is inserted as the
 * choices of its bytes, [1, b0, 1, b1, …].
 *
 * The spans of the bytes of a string are recognised by their kinds, so that
 * tokens can be inserted into every string that a structured target draws.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class InsertToken implements Mutation
{
    /**
     * @var list<non-empty-string>
     */
    private array $tokens;

    /**
     * @param list<non-empty-string> $tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        if ($this->tokens === []) {
            return false;
        }

        $positions = [];

        foreach ($candidate->spans() as $span) {
            if ($candidate->isByteOfString($span)) {
                $positions[] = $span;
            }
        }

        if ($positions === []) {
            return false;
        }

        $position = RandomElement::of($randomizer, $positions);
        $token    = RandomElement::of($randomizer, $this->tokens);

        // each byte is inserted as a span of its own, like the bytes that
        // were drawn
        for ($i = 0; $i < strlen($token); $i++) {
            $candidate->insert(
                $position['start'] + 2 * $i,
                [1, ord($token[$i])],
                [1, 0xFF],
                $position['label'],
                $position['parent'],
                SpanKind::Element,
            );
        }

        return true;
    }
}
