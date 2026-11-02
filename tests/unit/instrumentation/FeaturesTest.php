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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Features::class)]
#[Small]
final class FeaturesTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: positive-int}>
     */
    public static function hitCounts(): array
    {
        return [
            '1 hit'       => [0, 1],
            '2 hits'      => [1, 2],
            '3 hits'      => [2, 3],
            '4 hits'      => [3, 4],
            '7 hits'      => [3, 7],
            '8 hits'      => [4, 8],
            '15 hits'     => [4, 15],
            '16 hits'     => [5, 16],
            '31 hits'     => [5, 31],
            '32 hits'     => [6, 32],
            '127 hits'    => [6, 127],
            '128 hits'    => [7, 128],
            '100000 hits' => [7, 100000],
        ];
    }

    /**
     * @param positive-int $count
     */
    #[DataProvider('hitCounts')]
    public function testPutsHitCountsIntoBuckets(int $bucket, int $count): void
    {
        $this->assertSame($bucket, Features::bucketOf($count));
    }

    public function testCombinesTheBucketWithTheEdge(): void
    {
        $edge = 0x0FFFFFFF << 28 | 0x0FFFFFFF;

        $this->assertSame(
            [5, 1 << 56 | 6, 7 << 56 | $edge],
            Features::fromEdges([5 => 1, 6 => 2, $edge => 1000]),
        );
    }

    public function testFindsTheFeaturesThatWereNotSeenAndAddsThemToTheSeenFeatures(): void
    {
        $seen = [5 => true];

        $this->assertSame([1 << 56 | 6, 2 << 56 | 7], Features::unseen([5 => 1, 6 => 2, 7 => 3], $seen));
        $this->assertSame([5 => true, 1 << 56 | 6 => true, 2 << 56 | 7 => true], $seen);

        // another bucket of an edge that was seen is a feature of its own
        $this->assertSame([3 << 56 | 5], Features::unseen([5 => 4, 6 => 2], $seen));
        $this->assertSame([], Features::unseen([5 => 7, 6 => 2], $seen));
    }

    public function testTellsWhetherAnExecutionProducedFeatures(): void
    {
        $edge = 0x0FFFFFFF << 28 | 0x0FFFFFFF;
        $hits = [5 => 1, 6 => 2, $edge => 1000];

        $this->assertTrue(Features::produces($hits, []));
        $this->assertTrue(Features::produces($hits, [5, 7 << 56 | $edge]));
        $this->assertTrue(Features::produces($hits, Features::fromEdges($hits)));
        $this->assertFalse(Features::produces($hits, [1 << 56 | 5]));
        $this->assertFalse(Features::produces($hits, [5, 8]));
    }
}
