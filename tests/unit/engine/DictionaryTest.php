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

use function array_keys;
use function range;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(Dictionary::class)]
#[UsesClass(RandomElement::class)]
#[Small]
final class DictionaryTest extends TestCase
{
    public function testHasTheTokensOfTheDictionaryFile(): void
    {
        $dictionary = new Dictionary(['a', 'b', 'a']);

        $this->assertFalse($dictionary->isEmpty());
        $this->assertSame(3, $dictionary->numberOfTokens());
        $this->assertSame([], $dictionary->foundTokens());
    }

    public function testAddsTheTokensThatWereFoundOnce(): void
    {
        $dictionary = new Dictionary(['a']);

        $dictionary->addFoundTokens(['a', 'b', 'c']);
        $dictionary->addFoundTokens(['c', 'd']);

        // the token of the dictionary file is found, but not inserted twice
        $this->assertSame(4, $dictionary->numberOfTokens());
        $this->assertSame(['a', 'b', 'c', 'd'], $dictionary->foundTokens());
    }

    public function testAddsAtMost4096TokensThatWereFound(): void
    {
        $dictionary = new Dictionary;

        foreach (range(1, 5000) as $number) {
            $dictionary->addFoundTokens(['token ' . $number]);
        }

        $this->assertCount(4096, $dictionary->foundTokens());
        $this->assertSame(4096, $dictionary->numberOfTokens());
    }

    public function testChoosesEveryTokenAtRandom(): void
    {
        $dictionary = new Dictionary(['a']);
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $chosen     = [];

        $dictionary->addFoundTokens(['b', 'c']);

        for ($i = 0; $i < 100; $i++) {
            $chosen[$dictionary->randomToken($randomizer)] = true;
        }

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], array_keys($chosen));
    }

    public function testIsEmptyWithoutTokens(): void
    {
        $this->assertTrue((new Dictionary)->isEmpty());
    }
}
