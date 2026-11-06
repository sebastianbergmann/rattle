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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(CoveredPaths::class)]
#[UsesClass(Features::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[Small]
final class CoveredPathsTest extends TestCase
{
    public function testReportsTheFeaturesOfEveryPathOnlyOnce(): void
    {
        $paths = new CoveredPaths;

        $first  = $paths->addTo($this->passed(), [1 => 1, 2 => 3]);
        $second = $paths->addTo($this->passed(), [1 => 1, 2 => 3]);

        $this->assertNotNull($first->path());
        $this->assertSame($first->path(), $second->path());
        $this->assertSame(Features::fromEdges([1 => 1, 2 => 3]), $first->pathFeatures());
        $this->assertNull($second->pathFeatures());
    }

    public function testPathsThatTakeEdgesInAnotherOrderOrAsOftenAsFallsIntoAnotherBucketAreOtherPaths(): void
    {
        $paths = new CoveredPaths;

        $path         = $paths->addTo($this->passed(), [1 => 1, 2 => 3])->path();
        $otherOrder   = $paths->addTo($this->passed(), [2 => 3, 1 => 1]);
        $otherBucket  = $paths->addTo($this->passed(), [1 => 1, 2 => 4]);
        $sameBucket   = $paths->addTo($this->passed(), [1 => 1, 2 => 3])->path();
        $noEdges      = $paths->addTo($this->passed(), []);
        $noEdgesAgain = $paths->addTo($this->passed(), []);

        $this->assertNotSame($path, $otherOrder->path());
        $this->assertSame(Features::fromEdges([2 => 3, 1 => 1]), $otherOrder->pathFeatures());
        $this->assertNotSame($path, $otherBucket->path());
        $this->assertSame(Features::fromEdges([1 => 1, 2 => 4]), $otherBucket->pathFeatures());
        $this->assertSame($path, $sameBucket);
        $this->assertSame([], $noEdges->pathFeatures());
        $this->assertNull($noEdgesAgain->pathFeatures());
        $this->assertSame($noEdges->path(), $noEdgesAgain->path());
    }

    private function passed(): Result
    {
        return new Result(1, Outcome::Passed, null, null, [], null);
    }
}
