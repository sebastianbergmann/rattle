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
use PHPUnit\Framework\TestCase;

#[CoversClass(Statistics::class)]
#[Small]
final class StatisticsTest extends TestCase
{
    public function testHasTheTimeThatElapsedSinceTheStart(): void
    {
        $this->assertSame(2.5, (new Statistics(10.0))->elapsed(12.5));
    }

    public function testHasTheNumberOfExecutionsPerSecond(): void
    {
        $statistics             = new Statistics(10.0);
        $statistics->executions = 50;

        $this->assertSame(20.0, $statistics->executionsPerSecond(12.5));
    }

    public function testHasNoExecutionsPerSecondBeforeAnyTimeElapsed(): void
    {
        $statistics             = new Statistics(10.0);
        $statistics->executions = 50;

        $this->assertSame(0.0, $statistics->executionsPerSecond(10.0));
    }
}
