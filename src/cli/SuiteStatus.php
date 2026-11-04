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

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use function array_map;
use function glob;
use function json_encode;
use function max;
use function number_format;
use function sprintf;
use function strcmp;
use function strlen;
use function usort;
use JsonException;

/**
 * The status of the targets that were fuzzed with a cache directory, fuzz
 * targets and properties of a PHPUnit test suite, from the histories that the
 * runs kept next to the corpora of the targets (see TargetHistory). It is
 * written to status.json in the cache directory at the end of every run, and
 * "rattle status" shows it. A fuzz target is described by its name, and a
 * property by its test ID.
 *
 * A target that is no longer fuzzed, a property that is no longer in the
 * test suite, for instance, is shown until the directory of its corpus is
 * removed from the cache directory.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class SuiteStatus
{
    private const int VERSION = 1;

    /**
     * @var list<TargetHistory>
     */
    private array $histories;

    public static function fromCacheDirectory(string $directory): self
    {
        $histories = [];
        $files     = glob($directory . '/*/statistics.json');

        foreach ($files === false ? [] : $files as $file) {
            $history = TargetHistory::read($file);

            if ($history !== null) {
                $histories[] = $history;
            }
        }

        return new self($histories);
    }

    /**
     * How long ago a target last discovered features.
     */
    public static function lastNewFeature(TargetHistory $history, float $now): string
    {
        $last = $history->lastDiscovery();

        if ($last === null) {
            return 'never';
        }

        return StatusLine::duration(max(0.0, $now - $last['time'])) . ' ago';
    }

    /**
     * @param list<TargetHistory> $histories
     */
    public function __construct(array $histories)
    {
        usort($histories, static fn (TargetHistory $a, TargetHistory $b): int => strcmp($a->target(), $b->target()));

        $this->histories = $histories;
    }

    /**
     * The histories of the targets, ordered by their names and test IDs.
     *
     * @return list<TargetHistory>
     */
    public function histories(): array
    {
        return $this->histories;
    }

    public function isEmpty(): bool
    {
        return $this->histories === [];
    }

    /**
     * The status in JSON. Times are seconds since the Unix epoch, and the
     * points of a series are the time, the number of executions, and the
     * number of features.
     *
     * @throws JsonException
     */
    public function asJson(float $now): string
    {
        return json_encode(
            [
                'version'    => self::VERSION,
                'time'       => $now,
                'properties' => array_map(
                    static fn (TargetHistory $history): array => [
                        'property'       => $history->target(),
                        'runs'           => $history->runs(),
                        'executions'     => $history->executions(),
                        'seconds'        => $history->seconds(),
                        'features'       => $history->features(),
                        'findings'       => $history->findings(),
                        'lastNewFeature' => $history->lastDiscovery(),
                        'series'         => $history->series(),
                    ],
                    $this->histories,
                ),
            ],
            JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * The status as a table with a row for every target.
     */
    public function asTable(float $now): string
    {
        $width = strlen('Target');

        foreach ($this->histories as $history) {
            $width = max($width, strlen($history->target()));
        }

        $format = '%-' . $width . 's  %4s  %12s  %10s  %8s  %8s  %s' . PHP_EOL;
        $table  = sprintf($format, 'Target', 'Runs', 'Executions', 'Time', 'Features', 'Findings', 'Last new feature');

        foreach ($this->histories as $history) {
            $table .= sprintf(
                $format,
                $history->target(),
                number_format($history->runs()),
                number_format($history->executions()),
                StatusLine::duration($history->seconds()),
                number_format($history->features()),
                number_format($history->findings()),
                self::lastNewFeature($history, $now),
            );
        }

        return $table;
    }
}
