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

#[CoversClass(NewFeatures::class)]
#[UsesClass(Features::class)]
#[Small]
final class NewFeaturesTest extends TestCase
{
    public function testReportsEveryFeatureOnlyOnce(): void
    {
        $features = new NewFeatures;

        $this->assertSame(Features::fromEdges([1 => 1, 2 => 3]), $features->of([1 => 1, 2 => 3]));
        $this->assertSame([], $features->of([1 => 1, 2 => 3]));
        $this->assertSame(Features::fromEdges([3 => 1]), $features->of([1 => 1, 3 => 1]));
    }

    public function testReportsAllFeaturesOfAnExecutionWhenAskedForThem(): void
    {
        $features = new NewFeatures;

        $this->assertSame(Features::fromEdges([1 => 1]), $features->of([1 => 1]));
        $this->assertSame(Features::fromEdges([1 => 1, 2 => 1]), $features->all([1 => 1, 2 => 1]));

        // the features that all() returns are seen afterwards
        $this->assertSame([], $features->of([1 => 1, 2 => 1]));
    }
}
