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

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use function count;
use function gmdate;
use function htmlspecialchars;
use function implode;
use function intdiv;
use function log10;
use function max;
use function number_format;
use function sprintf;

/**
 * The status of the properties of a PHPUnit test suite (see SuiteStatus) as
 * one HTML file that needs nothing else, so that it can be kept as an
 * artifact of CI and opened without a network: a table with a row for every
 * property, and a chart for every property that shows the number of its
 * features over the number of its executions in all runs, on a logarithmic
 * scale, as progress in fuzzing slows down over time.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class StatusDashboard
{
    private const int WIDTH         = 640;
    private const int HEIGHT        = 220;
    private const int LEFT          = 56;
    private const int RIGHT         = 16;
    private const int TOP           = 12;
    private const int BOTTOM        = 36;
    private const string STYLESHEET = <<<'CSS'
:root {
    color-scheme: light dark;
    --background: #ffffff;
    --foreground: #1f2328;
    --muted: #656d76;
    --rule: #d0d7de;
    --line: #0969da;
    --area: rgba(9, 105, 218, 0.12);
}

@media (prefers-color-scheme: dark) {
    :root {
        --background: #0d1117;
        --foreground: #e6edf3;
        --muted: #8d96a0;
        --rule: #30363d;
        --line: #4493f8;
        --area: rgba(68, 147, 248, 0.16);
    }
}

body {
    margin: 0 auto;
    max-width: 1100px;
    padding: 24px 16px;
    background: var(--background);
    color: var(--foreground);
    font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
}

h1 { font-size: 22px; margin: 0 0 4px; }
h2 { font-size: 15px; margin: 32px 0 4px; overflow-wrap: anywhere; }
p.meta { color: var(--muted); margin: 0 0 24px; }
.table { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th, td { padding: 6px 10px; border-bottom: 1px solid var(--rule); text-align: right; white-space: nowrap; }
th:first-child, td:first-child { text-align: left; white-space: normal; overflow-wrap: anywhere; }
th { color: var(--muted); font-weight: 600; }
td { font-variant-numeric: tabular-nums; }
svg { display: block; width: 100%; height: auto; max-width: 720px; }
svg text { fill: var(--muted); font-size: 11px; }
svg .grid { stroke: var(--rule); stroke-width: 1; }
svg .line { fill: none; stroke: var(--line); stroke-width: 2; }
svg .area { fill: var(--area); stroke: none; }
svg .point { fill: var(--line); }
CSS;

    public function render(SuiteStatus $status, float $now): string
    {
        $histories = $status->histories();
        $rows      = '';
        $charts    = '';

        foreach ($histories as $history) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' . "\n",
                self::escape($history->target()),
                number_format($history->runs()),
                number_format($history->executions()),
                StatusLine::duration($history->seconds()),
                number_format($history->features()),
                number_format($history->findings()),
                self::escape(SuiteStatus::lastNewFeature($history, $now)),
            );

            $charts .= sprintf(
                "<h2>%s</h2>\n%s\n",
                self::escape($history->target()),
                $this->chart($history),
            );
        }

        return sprintf(
            <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>rattle status</title>
<style>
%s
</style>
</head>
<body>
<h1>rattle status</h1>
<p class="meta">%d propert%s of a PHPUnit test suite, as of %s UTC</p>
<div class="table">
<table>
<thead><tr><th>Property</th><th>Runs</th><th>Executions</th><th>Time</th><th>Features</th><th>Findings</th><th>Last new feature</th></tr></thead>
<tbody>
%s</tbody>
</table>
</div>
%s</body>
</html>

HTML,
            self::STYLESHEET,
            count($histories),
            count($histories) === 1 ? 'y' : 'ies',
            gmdate('Y-m-d H:i:s', (int) $now),
            $rows,
            $charts,
        );
    }

    /**
     * The number of features of a property over its executions, as steps,
     * with the executions on a logarithmic scale.
     */
    private function chart(TargetHistory $history): string
    {
        $series = $history->series();

        if ($series === []) {
            return '<p class="meta">Not fuzzed yet.</p>';
        }

        $maximumExecutions = 1;
        $maximumFeatures   = 1;

        foreach ($series as [, $executions, $features]) {
            $maximumExecutions = max($maximumExecutions, $executions);
            $maximumFeatures   = max($maximumFeatures, $features);
        }

        $right  = self::WIDTH - self::RIGHT;
        $bottom = self::HEIGHT - self::BOTTOM;
        $x      = static fn (int $executions): float => self::LEFT + ($right - self::LEFT) * log10($executions + 1) / log10($maximumExecutions + 1);
        $y      = static fn (int $features): float => $bottom - ($bottom - self::TOP) * $features / $maximumFeatures;
        $grid   = [];

        // a line for every power of ten of the executions
        for ($power = 1; $power <= $maximumExecutions; $power *= 10) {
            $grid[] = sprintf(
                '<line class="grid" x1="%.1F" y1="%d" x2="%.1F" y2="%d"/><text x="%.1F" y="%d" text-anchor="middle">%s</text>',
                $x($power),
                self::TOP,
                $x($power),
                $bottom,
                $x($power),
                $bottom + 16,
                self::abbreviated($power),
            );
        }

        foreach ([0, $maximumFeatures] as $features) {
            $grid[] = sprintf(
                '<line class="grid" x1="%d" y1="%.1F" x2="%d" y2="%.1F"/><text x="%d" y="%.1F" text-anchor="end">%s</text>',
                self::LEFT,
                $y($features),
                $right,
                $y($features),
                self::LEFT - 6,
                $y($features) + 4,
                number_format($features),
            );
        }

        $steps           = [];
        $firstExecutions = null;
        $lastExecutions  = 0;
        $lastFeatures    = 0;

        foreach ($series as [, $executions, $features]) {
            if ($firstExecutions === null) {
                $firstExecutions = $executions;
            } else {
                $steps[] = sprintf('%.1F,%.1F', $x($executions), $y($lastFeatures));
            }

            $steps[]        = sprintf('%.1F,%.1F', $x($executions), $y($features));
            $lastExecutions = $executions;
            $lastFeatures   = $features;
        }

        return sprintf(
            '<svg viewBox="0 0 %d %d" role="img" aria-label="%s features after %s executions">%s<path class="area" d="M%.1F,%d L%s L%.1F,%d Z"/><polyline class="line" points="%s"/><circle class="point" cx="%.1F" cy="%.1F" r="3"/><text x="%d" y="%d" text-anchor="middle">executions</text></svg>',
            self::WIDTH,
            self::HEIGHT,
            number_format($lastFeatures),
            number_format($lastExecutions),
            implode('', $grid),
            $x((int) $firstExecutions),
            $bottom,
            implode(' L', $steps),
            $x($lastExecutions),
            $bottom,
            implode(' ', $steps),
            $x($lastExecutions),
            $y($lastFeatures),
            intdiv(self::LEFT + $right, 2),
            self::HEIGHT - 4,
        );
    }

    private static function abbreviated(int $number): string
    {
        return match (true) {
            $number >= 1_000_000_000 => ($number / 1_000_000_000) . 'G',
            $number >= 1_000_000     => ($number / 1_000_000) . 'M',
            $number >= 1_000         => ($number / 1_000) . 'k',
            default                  => (string) $number,
        };
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
