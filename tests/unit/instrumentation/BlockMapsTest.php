<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockMaps::class)]
#[CoversClass(BlockMap::class)]
#[Small]
final class BlockMapsTest extends TestCase
{
    public function testReportsTheBlocksOfEachFileOnce(): void
    {
        $maps = new BlockMaps;
        $a    = new BlockMap('a.php', 3, [[1, 2]]);
        $b    = new BlockMap('b.php', 5, [[2, 1], [3, 4]]);

        $maps->add($a);
        $maps->add($b);
        $maps->add(new BlockMap('a.php', 3, [[1, 2]]));

        $this->assertSame([$a, $b], $maps->takeUnreported());
        $this->assertSame([], $maps->takeUnreported());

        $c = new BlockMap('c.php', 1, []);

        $maps->add($a);
        $maps->add($c);

        $this->assertSame([$c], $maps->takeUnreported());
    }

    public function testHasTheBlocksOfAFileAndItsNumberOfLines(): void
    {
        $map = new BlockMap('/path/to/a.php', 7, [[17, 2], [4, 5]]);

        $this->assertSame('/path/to/a.php', $map->file());
        $this->assertSame(7, $map->numberOfLines());
        $this->assertSame([[17, 2], [4, 5]], $map->blocks());
        $this->assertSame(['file' => '/path/to/a.php', 'lines' => 7, 'blocks' => [[17, 2], [4, 5]]], $map->asArray());

        $relative = $map->withFile('a.php');

        $this->assertSame('a.php', $relative->file());
        $this->assertSame(7, $relative->numberOfLines());
        $this->assertSame([[17, 2], [4, 5]], $relative->blocks());
    }
}
