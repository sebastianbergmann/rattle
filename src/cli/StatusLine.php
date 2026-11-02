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

use function floor;
use function fwrite;
use function intdiv;
use function number_format;
use function sprintf;
use function str_repeat;
use function strlen;
use Closure;

/**
 * Shows how fuzzing progresses: every second on a terminal, where the line is
 * updated in place, and every ten seconds otherwise. Each new finding gets a
 * line of its own.
 *
 * When no file below the source directories was instrumented after the first
 * 1,000 executions, or by the end of a shorter run, a warning gets a line of
 * its own, as no coverage guides fuzzing then.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class StatusLine
{
    private const int EXECUTIONS_BEFORE_WARNING = 1000;

    /**
     * @var resource
     */
    private readonly mixed $stream;
    private readonly bool $terminal;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;
    private float $lastUpdate = 0.0;
    private string $line      = '';
    private bool $warned      = false;

    public static function format(Statistics $statistics, float $now): string
    {
        return sprintf(
            'executions: %s (%s/s), features: %s, corpus: %s, tokens: %s, findings: %s, rejected: %s, time: %s',
            number_format($statistics->executions),
            number_format($statistics->executionsPerSecond($now)),
            number_format($statistics->features),
            number_format($statistics->corpusEntries),
            number_format($statistics->tokens),
            number_format($statistics->findings),
            number_format($statistics->rejected),
            self::duration($statistics->elapsed($now)),
        );
    }

    public static function duration(float $seconds): string
    {
        $seconds = (int) floor($seconds);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%dh%02dm%02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * @param resource         $stream
     * @param Closure(): float $clock
     */
    public function __construct(mixed $stream, bool $terminal, Closure $clock)
    {
        $this->stream   = $stream;
        $this->terminal = $terminal;
        $this->clock    = $clock;
    }

    /**
     * @param string $prefix comes before the statistics, the number of properties that are fuzzed, for instance
     */
    public function update(Statistics $statistics, string $prefix = ''): void
    {
        if ($statistics->executions >= self::EXECUTIONS_BEFORE_WARNING) {
            $this->warnWhenNothingWasInstrumented($statistics);
        }

        $now = ($this->clock)();

        if ($now - $this->lastUpdate < ($this->terminal ? 1.0 : 10.0)) {
            return;
        }

        $this->lastUpdate = $now;

        $this->show($statistics, $now, $prefix);
    }

    public function finding(string $text): void
    {
        $this->clear();

        fwrite($this->stream, $text . "\n");

        if ($this->terminal && $this->line !== '') {
            fwrite($this->stream, $this->line);
        }
    }

    public function finish(Statistics $statistics, string $prefix = ''): void
    {
        if ($statistics->executions > 0) {
            $this->warnWhenNothingWasInstrumented($statistics);
        }

        $this->show($statistics, ($this->clock)(), $prefix);

        if ($this->terminal) {
            fwrite($this->stream, "\n");
        }

        $this->line = '';
    }

    private function warnWhenNothingWasInstrumented(Statistics $statistics): void
    {
        if ($this->warned || $statistics->instrumentedFiles > 0) {
            return;
        }

        $this->warned = true;

        $this->finding(
            'Warning: No file below the source directories was instrumented, so no coverage guides fuzzing. The code that is fuzzed is not below them, or it is in a vendor directory below one of them.',
        );
    }

    private function show(Statistics $statistics, float $now, string $prefix): void
    {
        $line = $prefix . self::format($statistics, $now);

        if ($this->terminal) {
            $this->clear();

            $this->line = $line;

            fwrite($this->stream, $line);

            return;
        }

        fwrite($this->stream, $line . "\n");
    }

    private function clear(): void
    {
        if ($this->terminal && $this->line !== '') {
            fwrite($this->stream, "\r" . str_repeat(' ', strlen($this->line)) . "\r");
        }
    }
}
