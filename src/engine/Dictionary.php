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
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Dictionary
{
    private const int LARGEST_NUMBER_OF_FOUND_TOKENS = 4096;

    /**
     * @var list<non-empty-string>
     */
    private array $tokens;

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
     * @param list<non-empty-string> $tokens the tokens of the dictionary file
     */
    public function __construct(array $tokens = [])
    {
        $this->tokens = $tokens;

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

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /**
     * One of the tokens, chosen uniformly at random. The dictionary must not
     * be empty.
     *
     * @return non-empty-string
     */
    public function randomToken(Randomizer $randomizer): string
    {
        assert($this->tokens !== []);

        return RandomElement::of($randomizer, $this->tokens);
    }

    /**
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
