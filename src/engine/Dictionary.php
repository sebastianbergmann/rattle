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

use function count;
use Random\Randomizer;

/**
 * The tokens that are added to the strings of inputs (see AddToken):
 * the tokens of the dictionary file of --dictionary, and the constant
 * operands of comparisons that the workers found in the code that they
 * instrumented, which are added while the target is fuzzed.
 *
 * At most 4096 tokens are found, so that the code of a large project does
 * not dilute the tokens of the dictionary file, and a token that was found
 * is only inserted when the dictionary file does not have it.
 *
 * The sentinels of the detectors (see Sentinels) are added as well, as one
 * of 64 tokens, however many other tokens there are, so that they neither
 * dilute a small dictionary nor are diluted by a large one, and take little
 * from fuzzing a target that does not reach a dangerous sink.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Dictionary
{
    private const int LARGEST_NUMBER_OF_FOUND_TOKENS = 4096;
    private const float SHARE_OF_SENTINELS           = 1 / 64;

    /**
     * @var list<non-empty-string>
     */
    private array $tokens;

    /**
     * @var list<non-empty-string>
     */
    private readonly array $sentinels;

    /**
     * The tokens that are added to strings, as keys.
     *
     * @var array<string, true>
     */
    private array $known = [];

    /**
     * @var list<non-empty-string>
     */
    private array $foundTokens = [];

    /**
     * The tokens that were found, as keys.
     *
     * @var array<string, true>
     */
    private array $found = [];

    /**
     * @param list<non-empty-string> $tokens    the tokens of the dictionary file
     * @param list<non-empty-string> $sentinels the sentinels of the detectors
     */
    public function __construct(array $tokens = [], array $sentinels = [])
    {
        $this->tokens    = $tokens;
        $this->sentinels = $sentinels;

        foreach ($tokens as $token) {
            $this->known[$token] = true;
        }
    }

    /**
     * Adds tokens that were found in the code that was instrumented. A token
     * that the dictionary file has is found as well, but not inserted twice
     * as often as the other tokens.
     *
     * @param list<non-empty-string> $tokens
     */
    public function addFoundTokens(array $tokens): void
    {
        foreach ($tokens as $token) {
            if (count($this->foundTokens) === self::LARGEST_NUMBER_OF_FOUND_TOKENS) {
                return;
            }

            if (isset($this->found[$token])) {
                continue;
            }

            $this->foundTokens[] = $token;
            $this->found[$token] = true;

            if (!isset($this->known[$token])) {
                $this->tokens[]      = $token;
                $this->known[$token] = true;
            }
        }
    }

    /**
     * Whether there is nothing to add to strings, neither tokens nor
     * sentinels.
     */
    public function isEmpty(): bool
    {
        return $this->tokens === [] && $this->sentinels === [];
    }

    /**
     * One of the sentinels in one of 64 draws, and otherwise one of the
     * tokens, chosen uniformly at random, or null when there are none.
     *
     * @return ?non-empty-string
     */
    public function randomToken(Randomizer $randomizer): ?string
    {
        if ($this->sentinels !== [] && $randomizer->nextFloat() < self::SHARE_OF_SENTINELS) {
            return RandomElement::of($randomizer, $this->sentinels);
        }

        if ($this->tokens === []) {
            return null;
        }

        return RandomElement::of($randomizer, $this->tokens);
    }

    /**
     * The number of the tokens, without the sentinels.
     *
     * @return non-negative-int
     */
    public function numberOfTokens(): int
    {
        return count($this->tokens);
    }

    /**
     * The tokens that were found in the code that was instrumented and added,
     * in the order in which they were added.
     *
     * @return list<non-empty-string>
     */
    public function foundTokens(): array
    {
        return $this->foundTokens;
    }
}
