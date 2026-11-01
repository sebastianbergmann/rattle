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
 * The tokens that are inserted into the strings of inputs (see InsertToken):
 * the tokens of the dictionary file of --dictionary, and the constant
 * operands of comparisons that the workers found in the code that they
 * instrumented, which are added while the target is fuzzed.
 *
 * A token that was found is added only when the dictionary does not have it
 * yet, and at most 4096 tokens are added, so that the code of a large
 * project does not dilute the tokens of the dictionary file.
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
     * @var array<string, true>
     */
    private array $known = [];

    /**
     * @var non-negative-int
     */
    private int $numberOfFoundTokens = 0;

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
     * Adds tokens that were found in the code that was instrumented.
     *
     * @param list<non-empty-string> $tokens
     */
    public function addFoundTokens(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($this->numberOfFoundTokens === self::LARGEST_NUMBER_OF_FOUND_TOKENS) {
                return;
            }

            if (isset($this->known[$token])) {
                continue;
            }

            $this->tokens[]      = $token;
            $this->known[$token] = true;

            $this->numberOfFoundTokens++;
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
     * The number of tokens that were found in the code that was instrumented
     * and added.
     *
     * @return non-negative-int
     */
    public function numberOfFoundTokens(): int
    {
        return $this->numberOfFoundTokens;
    }
}
