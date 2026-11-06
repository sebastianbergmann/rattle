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

use function file_get_contents;
use function glob;
use function is_file;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusCommand::class)]
#[UsesClass(Arguments::class)]
#[UsesClass(ArgumentsBuilder::class)]
#[UsesClass(StatusDashboard::class)]
#[UsesClass(StatusLine::class)]
#[UsesClass(SuiteStatus::class)]
#[UsesClass(TargetHistory::class)]
#[Small]
final class StatusCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-status-command-' . uniqid();
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

    public function testShowsTheStatusOfTheTargetsThatWereFuzzedWithTheCacheDirectoryInATable(): void
    {
        $this->fuzzed('csv');

        [$exit, $output] = $this->runStatusCommand([]);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^Target +Runs +Executions +Time +Features +Findings +Last new feature$/m', $output);
        $this->assertMatchesRegularExpression('/^csv +1 +1,500 +1m05s +12 +1 +.+ ago$/m', $output);
    }

    public function testShowsTheStatusOfTheTargetsThatWereFuzzedWithTheCacheDirectoryInJson(): void
    {
        $this->fuzzed('csv');

        [$exit, $output] = $this->runStatusCommand(['--json']);

        $this->assertSame(0, $exit);
        $this->assertSame('csv', JsonValue::of($output, 'properties', 0, 'property'));
        $this->assertSame(1500, JsonValue::of($output, 'properties', 0, 'executions'));
    }

    public function testWritesTheStatusOfTheTargetsThatWereFuzzedWithTheCacheDirectoryAsADashboardInHtml(): void
    {
        $this->fuzzed('csv');

        $file = $this->directory . '/dashboard.html';

        [$exit, $output] = $this->runStatusCommand(['--html', $file]);

        try {
            $this->assertSame(0, $exit);
            $this->assertSame('The dashboard was written to ' . $file . ".\n", $output);
            $this->assertStringContainsString('csv', (string) file_get_contents($file));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReportsThatTheDashboardCannotBeWritten(): void
    {
        $this->fuzzed('csv');

        [$exit, $output] = $this->runStatusCommand(['--html', '/dev/null/dashboard.html']);

        $this->assertSame(2, $exit);
        $this->assertSame('Cannot write the dashboard to "/dev/null/dashboard.html"' . "\n", $output);
    }

    public function testReportsThatNothingWasFuzzedWithTheCacheDirectory(): void
    {
        [$exit, $output] = $this->runStatusCommand([]);

        $this->assertSame(2, $exit);
        $this->assertSame('Nothing was fuzzed with the cache directory ' . $this->directory . "\n", $output);
    }

    /**
     * @param non-empty-string $target
     */
    private function fuzzed(string $target): void
    {
        mkdir($this->directory . '/' . $target, 0o777, true);

        $history = new TargetHistory($target);

        $history->begin(1000.0);
        $history->discover(1100.0, 40, 12);
        $history->end(1130.0, 1.0, 1400.0, 1500, 65.0, 12, 1);

        $this->assertTrue($history->write($this->directory . '/' . $target . '/statistics.json'));
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: int, 1: string}
     */
    private function runStatusCommand(array $options): array
    {
        ob_start();

        $exit = (new StatusCommand)->run((new ArgumentsBuilder)->build(['rattle', 'status', '--cache-directory', $this->directory, ...$options]));

        return [$exit, (string) ob_get_clean()];
    }
}
