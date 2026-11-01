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

use const JSON_THROW_ON_ERROR;
use function array_map;
use function file_put_contents;
use function glob;
use function json_decode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuiteStatus::class)]
#[UsesClass(StatusLine::class)]
#[UsesClass(TargetHistory::class)]
#[Small]
final class SuiteStatusTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-status-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*/statistics.json') as $file) {
            unlink((string) $file);
        }

        foreach ((array) glob($this->directory . '/*') as $directory) {
            rmdir((string) $directory);
        }

        @rmdir($this->directory);
    }

    public function testReadsTheHistoriesOfThePropertiesInTheCacheDirectoryOrderedByTheirTestIds(): void
    {
        $this->history('b', 'ParserTest::testRoundTrip')->write($this->directory . '/b/statistics.json');
        $this->history('a', 'LexerTest::testTokens')->write($this->directory . '/a/statistics.json');

        mkdir($this->directory . '/c');
        file_put_contents($this->directory . '/c/statistics.json', 'not JSON');

        $status = SuiteStatus::fromCacheDirectory($this->directory);

        $this->assertFalse($status->isEmpty());
        $this->assertSame(['LexerTest::testTokens', 'ParserTest::testRoundTrip'], array_map(static fn (TargetHistory $history): string => $history->target(), $status->histories()));
        $this->assertTrue(SuiteStatus::fromCacheDirectory($this->directory . '/does-not-exist')->isEmpty());
    }

    public function testShowsTheStatusOfEveryPropertyInATable(): void
    {
        $status = new SuiteStatus([$this->history(null, 'ParserTest::testRoundTrip'), new TargetHistory('LexerTest::testTokens')]);

        $this->assertSame(
            <<<'EOT'
Property                   Runs    Executions        Time  Features  Findings  Last new feature
LexerTest::testTokens         0             0          0s         0         0  never
ParserTest::testRoundTrip     1         1,500       1m05s        12         1  1m40s ago

EOT,
            $status->asTable(1200.0),
        );
    }

    public function testShowsTheStatusInJson(): void
    {
        $status = new SuiteStatus([$this->history(null, 'ParserTest::testRoundTrip')]);
        $json   = json_decode($status->asJson(1200.0), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'version'    => 1,
                'time'       => 1200.0,
                'properties' => [
                    [
                        'property'       => 'ParserTest::testRoundTrip',
                        'runs'           => 1,
                        'executions'     => 1500,
                        'seconds'        => 65.0,
                        'features'       => 12,
                        'findings'       => 1,
                        'lastNewFeature' => ['time' => 1100.0, 'executions' => 40],
                        'series'         => [[1000.0, 0, 0], [1100.0, 40, 12], [1130.0, 1500, 12]],
                    ],
                ],
            ],
            $json,
        );
    }

    /**
     * @param non-empty-string $target
     */
    private function history(?string $directory, string $target): TargetHistory
    {
        if ($directory !== null) {
            @mkdir($this->directory . '/' . $directory, 0o777, true);
        }

        $history = new TargetHistory($target);

        $history->begin(1000.0);
        $history->discover(1100.0, 40, 12);
        $history->end(1130.0, 1.0, 1400.0, 1500, 65.0, 12, 1);

        return $history;
    }
}
