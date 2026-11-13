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
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetHistory::class)]
#[Small]
final class TargetHistoryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rattle-history-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testANewHistoryKnowsNothing(): void
    {
        $history = TargetHistory::fromFile($this->file, 'ParserTest::testRoundTrip');

        $this->assertSame('ParserTest::testRoundTrip', $history->target());
        $this->assertSame(0.0, $history->discoveries());
        $this->assertSame(0.0, $history->decayedExecutions());
        $this->assertSame(0, $history->runs());
        $this->assertSame(0, $history->executions());
        $this->assertSame(0.0, $history->seconds());
        $this->assertSame(0, $history->features());
        $this->assertSame(0, $history->findings());
        $this->assertNull($history->lastDiscovery());
        $this->assertSame([], $history->series());
    }

    public function testRecordsTheRunsOfTheTargetAndAccumulatesTheirTotals(): void
    {
        $history = new TargetHistory('ParserTest::testRoundTrip');

        $history->begin(100.0);
        $history->discover(101.0, 40, 7);
        $history->end(110.0, 3.5, 950.0, 1000, 8.0, 9, 1);

        $history->begin(200.0);
        $history->end(205.0, 1.5, 1800.0, 500, 4.5, 9, 0);

        $this->assertSame(1.5, $history->discoveries());
        $this->assertSame(1800.0, $history->decayedExecutions());
        $this->assertSame(2, $history->runs());
        $this->assertSame(1500, $history->executions());
        $this->assertSame(12.5, $history->seconds());
        $this->assertSame(9, $history->features());
        $this->assertSame(1, $history->findings());
        $this->assertSame(['time' => 101.0, 'executions' => 40], $history->lastDiscovery());
        $this->assertSame(
            [
                [100.0, 0, 0],
                [101.0, 40, 7],
                [110.0, 1000, 9],
                [200.0, 1000, 9],
                [205.0, 1500, 9],
            ],
            $history->series(),
        );
    }

    public function testIsKeptInAFile(): void
    {
        $history = new TargetHistory('ParserTest::testRoundTrip');

        $history->begin(100.0);
        $history->discover(101.0, 40, 7);
        $history->end(110.0, 3.5, 950.0, 1000, 8.0, 9, 1);

        $this->assertTrue($history->write($this->file));
        $this->assertSame($history->asArray(), TargetHistory::fromFile($this->file, 'ParserTest::testRoundTrip')->asArray());
    }

    public function testAFileThatCannotBeReadOrBelongsToAnotherTargetStartsANewHistory(): void
    {
        $other = new TargetHistory('OtherTest::testOther');

        $other->begin(1.0);
        $other->write($this->file);

        $ofAnotherTarget = TargetHistory::fromFile($this->file, 'ParserTest::testRoundTrip');

        file_put_contents($this->file, '{"version": 1, "target"');

        $notValid = TargetHistory::fromFile($this->file, 'ParserTest::testRoundTrip');

        file_put_contents($this->file, '{"version": 2, "target": "ParserTest::testRoundTrip", "runs": 3}');

        $ofAnotherVersion = TargetHistory::fromFile($this->file, 'ParserTest::testRoundTrip');

        $this->assertSame(0, $ofAnotherTarget->runs());
        $this->assertSame(0, $notValid->runs());
        $this->assertSame(0, $ofAnotherVersion->runs());
        $this->assertNull(TargetHistory::fromArray(['version' => 1, 'target' => '']));
    }

    public function testIgnoresValuesThatAreNotValid(): void
    {
        $history = TargetHistory::fromArray(
            [
                'version'       => 1,
                'target'        => 'ParserTest::testRoundTrip',
                'runs'          => -1,
                'executions'    => 'many',
                'seconds'       => 2,
                'lastDiscovery' => ['time' => 5, 'executions' => 3],
                'series'        => [[1, 2, 3], [1, 2], 'point'],
            ],
        );

        $this->assertNotNull($history);
        $this->assertSame(0, $history->runs());
        $this->assertSame(0, $history->executions());
        $this->assertSame(2.0, $history->seconds());
        $this->assertSame(['time' => 5.0, 'executions' => 3], $history->lastDiscovery());
        $this->assertSame([[1.0, 2, 3]], $history->series());
    }

    public function testThinsTheSeriesOutToAtMost1000PointsKeepingTheFirstAndTheLast(): void
    {
        $history = new TargetHistory('ParserTest::testRoundTrip');

        $history->begin(0.0);

        for ($i = 1; $i <= 2500; $i++) {
            $history->discover((float) $i, $i, $i);
        }

        $series = $history->series();

        $this->assertLessThanOrEqual(TargetHistory::LARGEST_NUMBER_OF_POINTS, count($series));
        $this->assertGreaterThan(TargetHistory::LARGEST_NUMBER_OF_POINTS / 2, count($series));
        $this->assertSame([0.0, 0, 0], $series[0]);
        $this->assertSame([2500.0, 2500, 2500], $series[count($series) - 1] ?? null);
    }
}
