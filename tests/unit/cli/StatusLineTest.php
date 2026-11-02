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

use function explode;
use function fopen;
use function rewind;
use function stream_get_contents;
use function substr_count;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusLine::class)]
#[UsesClass(Statistics::class)]
#[Small]
final class StatusLineTest extends TestCase
{
    private float $now = 0.0;

    public function testFormatsTheStatistics(): void
    {
        $statistics = $this->statistics();

        $this->assertSame(
            'executions: 12,345 (1,235/s), features: 67, corpus: 8, tokens: 3, findings: 2, rejected: 9, time: 10s',
            StatusLine::format($statistics, 10.0),
        );
        $this->assertStringEndsWith('time: 2m05s', StatusLine::format($statistics, 125.0));
        $this->assertStringEndsWith('time: 1h01m01s', StatusLine::format($statistics, 3661.0));
    }

    public function testShowsAPrefixBeforeTheStatistics(): void
    {
        $stream = $this->stream();
        $status = new StatusLine($stream, false, fn (): float => $this->now);

        $this->now = 10.0;
        $status->update($this->statistics(), 'properties: 1 of 2, ');

        $this->assertSame(
            "properties: 1 of 2, executions: 12,345 (1,235/s), features: 67, corpus: 8, tokens: 3, findings: 2, rejected: 9, time: 10s\n",
            $this->contents($stream),
        );
    }

    public function testUpdatesALineEveryTenSecondsWhenTheOutputIsNotATerminal(): void
    {
        $stream = $this->stream();
        $status = new StatusLine($stream, false, fn (): float => $this->now);

        $this->now = 10.0;
        $status->update($this->statistics());

        $this->now = 15.0;
        $status->update($this->statistics());
        $status->finding('Finding: something');

        $this->now = 20.0;
        $status->update($this->statistics());
        $status->finish($this->statistics());

        $this->assertSame(
            [
                'executions: 12,345 (1,235/s), features: 67, corpus: 8, tokens: 3, findings: 2, rejected: 9, time: 10s',
                'Finding: something',
                'executions: 12,345 (617/s), features: 67, corpus: 8, tokens: 3, findings: 2, rejected: 9, time: 20s',
                'executions: 12,345 (617/s), features: 67, corpus: 8, tokens: 3, findings: 2, rejected: 9, time: 20s',
                '',
            ],
            explode("\n", $this->contents($stream)),
        );
    }

    public function testUpdatesTheLineInPlaceEverySecondOnATerminal(): void
    {
        $stream = $this->stream();
        $status = new StatusLine($stream, true, fn (): float => $this->now);

        $this->now = 1.0;
        $status->update($this->statistics());

        $this->now = 1.5;
        $status->update($this->statistics());

        $this->now = 2.0;
        $status->update($this->statistics());
        $status->finding('Finding: something');
        $status->finish($this->statistics());

        $contents = $this->contents($stream);

        // shown at 1s and 2s, again after the finding, and when finished
        $this->assertSame(4, substr_count($contents, 'executions: '));
        $this->assertStringContainsString("\r", $contents);
        $this->assertStringContainsString("Finding: something\n", $contents);
        $this->assertStringEndsWith("time: 2s\n", $contents);
    }

    public function testWarnsOnceWhenNoFileWasInstrumentedAfterTheFirstThousandExecutions(): void
    {
        $stream     = $this->stream();
        $status     = new StatusLine($stream, false, fn (): float => $this->now);
        $statistics = $this->statistics(0);

        $statistics->executions = 999;
        $status->update($statistics);

        $this->assertStringNotContainsString('Warning', $this->contents($stream));

        $statistics->executions = 1000;
        $this->now              = 1.0;
        $status->update($statistics);
        $status->update($statistics);
        $status->finish($statistics);

        $this->assertSame(1, substr_count($this->contents($stream), 'Warning: No file below the source directories was instrumented, so no coverage guides fuzzing.'));
    }

    public function testWarnsAtTheEndOfAShorterRunWhenNoFileWasInstrumented(): void
    {
        $stream     = $this->stream();
        $status     = new StatusLine($stream, false, fn (): float => $this->now);
        $statistics = $this->statistics(0);

        $statistics->executions = 10;
        $status->update($statistics);

        $this->assertStringNotContainsString('Warning', $this->contents($stream));

        $status->finish($statistics);

        $this->assertStringContainsString('Warning: No file below the source directories was instrumented', $this->contents($stream));
    }

    public function testDoesNotWarnWhenAFileWasInstrumented(): void
    {
        $stream = $this->stream();
        $status = new StatusLine($stream, false, fn (): float => $this->now);

        $this->now = 10.0;
        $status->update($this->statistics());
        $status->finish($this->statistics());

        $this->assertStringNotContainsString('Warning', $this->contents($stream));
    }

    /**
     * @param non-negative-int $instrumentedFiles
     */
    private function statistics(int $instrumentedFiles = 4): Statistics
    {
        $statistics = new Statistics(0.0);

        $statistics->executions        = 12345;
        $statistics->features          = 67;
        $statistics->corpusEntries     = 8;
        $statistics->tokens            = 3;
        $statistics->findings          = 2;
        $statistics->rejected          = 9;
        $statistics->instrumentedFiles = $instrumentedFiles;

        return $statistics;
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
