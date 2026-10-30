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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(RandomElement::class)]
#[Small]
final class RandomElementTest extends TestCase
{
    public function testChoosesEveryElementOfAnArrayWithArbitraryKeys(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $seen       = [];

        for ($i = 0; $i < 100; $i++) {
            $seen[RandomElement::of($randomizer, [3 => 'a', 7 => 'b', 'x' => 'c'])] = true;
        }

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], array_keys($seen));
    }
}
