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

use function substr_count;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusDashboard::class)]
#[UsesClass(StatusLine::class)]
#[UsesClass(SuiteStatus::class)]
#[UsesClass(TargetHistory::class)]
#[Small]
final class StatusDashboardTest extends TestCase
{
    public function testShowsATableAndAChartOfTheFeaturesOverTheExecutionsOfEveryPropertyInOneFile(): void
    {
        $history = new TargetHistory('ParserTest::testRoundTrip<script>');

        $history->begin(1000.0);
        $history->discover(1001.0, 9, 4);
        $history->discover(1002.0, 120, 7);
        $history->end(1010.0, 1.0, 900.0, 2500, 10.0, 7, 1);

        $html = (new StatusDashboard)->render(new SuiteStatus([$history, new TargetHistory('LexerTest::testTokens')]), 1100.0);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<title>rattle status</title>', $html);
        $this->assertStringContainsString('2 properties of a PHPUnit test suite, as of 1970-01-01 00:18:20 UTC', $html);
        $this->assertStringContainsString('<tr><td>ParserTest::testRoundTrip&lt;script&gt;</td><td>1</td><td>2,500</td><td>10s</td><td>7</td><td>1</td><td>1m38s ago</td></tr>', $html);
        $this->assertStringContainsString('<tr><td>LexerTest::testTokens</td><td>0</td><td>0</td><td>0s</td><td>0</td><td>0</td><td>never</td></tr>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(1, substr_count($html, '<svg'));
        $this->assertStringContainsString('aria-label="7 features after 2,500 executions"', $html);
        $this->assertStringContainsString('>1k</text>', $html);
        $this->assertStringContainsString('Not fuzzed yet.', $html);
        $this->assertStringNotContainsString('http', $html);
    }
}
