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
use const JSON_UNESCAPED_SLASHES;
use function count;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function round;
use JsonException;

/**
 * What rattle knows about a target, a property of a PHPUnit test suite, from
 * the runs that fuzzed it. It is kept next to the corpus of the target, so
 * that a run continues where the runs before it stopped (see TargetScheduler):
 *
 * - the decayed number of the features that the target discovered and the
 *   decayed number of its executions, from which the scheduler weighs it
 * - the number of its features at the end of the last run
 * - totals over all runs: the number of runs, of executions, of findings, the
 *   time, and when the target last discovered features
 * - the number of its features over its executions and over time, as a
 *   series of points: at the start and at the end of every run, and whenever
 *   it discovered features. When there are more than 1,000 points, every
 *   other one is removed, keeping the first and the last.
 *
 * The time of a target is its share of the time of a run, in proportion to
 * its executions, as the targets of a run are fuzzed together.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class TargetHistory
{
    public const int LARGEST_NUMBER_OF_POINTS = 1000;
    private const int VERSION                 = 1;

    /**
     * @var non-empty-string
     */
    private readonly string $target;
    private float $discoveries       = 0.0;
    private float $decayedExecutions = 0.0;

    /**
     * @var non-negative-int
     */
    private int $runs = 0;

    /**
     * @var non-negative-int
     */
    private int $executions = 0;
    private float $seconds  = 0.0;

    /**
     * @var non-negative-int
     */
    private int $features = 0;

    /**
     * @var non-negative-int
     */
    private int $findings = 0;

    /**
     * @var ?array{time: float, executions: non-negative-int}
     */
    private ?array $lastDiscovery = null;

    /**
     * The time, the number of executions, and the number of features.
     *
     * @var list<array{0: float, 1: non-negative-int, 2: non-negative-int}>
     */
    private array $series = [];

    /**
     * Reads the history of a target from a file, or starts a new one when the
     * file does not exist, cannot be read, or belongs to another target.
     *
     * @param non-empty-string $target
     */
    public static function fromFile(string $file, string $target): self
    {
        $history = self::read($file);

        if ($history === null || $history->target !== $target) {
            return new self($target);
        }

        return $history;
    }

    /**
     * Reads the history of a target from a file, or returns null when the
     * file does not exist or cannot be read.
     */
    public static function read(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['version'] ?? null) !== self::VERSION || !is_string($data['target'] ?? null) || $data['target'] === '') {
            return null;
        }

        $history = new self($data['target']);

        $history->discoveries       = self::nonNegativeFloat($data['discoveries'] ?? null);
        $history->decayedExecutions = self::nonNegativeFloat($data['decayedExecutions'] ?? null);
        $history->runs              = self::nonNegativeInt($data['runs'] ?? null);
        $history->executions        = self::nonNegativeInt($data['executions'] ?? null);
        $history->seconds           = self::nonNegativeFloat($data['seconds'] ?? null);
        $history->features          = self::nonNegativeInt($data['features'] ?? null);
        $history->findings          = self::nonNegativeInt($data['findings'] ?? null);

        $lastDiscovery = $data['lastDiscovery'] ?? null;

        if (is_array($lastDiscovery)) {
            $history->lastDiscovery = [
                'time'       => self::nonNegativeFloat($lastDiscovery['time'] ?? null),
                'executions' => self::nonNegativeInt($lastDiscovery['executions'] ?? null),
            ];
        }

        foreach (is_array($data['series'] ?? null) ? $data['series'] : [] as $point) {
            if (is_array($point) && count($point) === 3) {
                $history->series[] = [
                    self::nonNegativeFloat($point[0] ?? null),
                    self::nonNegativeInt($point[1] ?? null),
                    self::nonNegativeInt($point[2] ?? null),
                ];
            }
        }

        return $history;
    }

    /**
     * @param non-empty-string $target
     */
    public function __construct(string $target)
    {
        $this->target = $target;
    }

    /**
     * Writes the history to a file, and returns whether that succeeded.
     */
    public function write(string $file): bool
    {
        try {
            $json = json_encode($this->asArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        return @file_put_contents($file, $json . "\n") !== false;
    }

    /**
     * @return array{version: int, target: non-empty-string, discoveries: float, decayedExecutions: float, runs: non-negative-int, executions: non-negative-int, seconds: float, features: non-negative-int, findings: non-negative-int, lastDiscovery: ?array{time: float, executions: non-negative-int}, series: list<array{0: float, 1: non-negative-int, 2: non-negative-int}>}
     */
    public function asArray(): array
    {
        return [
            'version'           => self::VERSION,
            'target'            => $this->target,
            'discoveries'       => $this->discoveries,
            'decayedExecutions' => $this->decayedExecutions,
            'runs'              => $this->runs,
            'executions'        => $this->executions,
            'seconds'           => $this->seconds,
            'features'          => $this->features,
            'findings'          => $this->findings,
            'lastDiscovery'     => $this->lastDiscovery,
            'series'            => $this->series,
        ];
    }

    /**
     * @return non-empty-string
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * The decayed number of the features that the target discovered.
     */
    public function discoveries(): float
    {
        return $this->discoveries;
    }

    /**
     * The decayed number of the executions of the target.
     */
    public function decayedExecutions(): float
    {
        return $this->decayedExecutions;
    }

    /**
     * @return non-negative-int
     */
    public function runs(): int
    {
        return $this->runs;
    }

    /**
     * @return non-negative-int
     */
    public function executions(): int
    {
        return $this->executions;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * The number of the features of the target at the end of the last run.
     *
     * @return non-negative-int
     */
    public function features(): int
    {
        return $this->features;
    }

    /**
     * @return non-negative-int
     */
    public function findings(): int
    {
        return $this->findings;
    }

    /**
     * When the target last discovered features, and after how many of its
     * executions in all runs, or null when it never did.
     *
     * @return ?array{time: float, executions: non-negative-int}
     */
    public function lastDiscovery(): ?array
    {
        return $this->lastDiscovery;
    }

    /**
     * @return list<array{0: float, 1: non-negative-int, 2: non-negative-int}>
     */
    public function series(): array
    {
        return $this->series;
    }

    /**
     * A run starts.
     */
    public function begin(float $time): void
    {
        $this->runs++;

        $this->addPoint($time, $this->executions, $this->features);
    }

    /**
     * The target discovered features during the run.
     *
     * @param non-negative-int $executions the executions of the target in the run so far
     * @param non-negative-int $features   the features of the target now
     */
    public function discover(float $time, int $executions, int $features): void
    {
        $this->lastDiscovery = ['time' => $time, 'executions' => $this->executions + $executions];

        $this->addPoint($time, $this->executions + $executions, $features);
    }

    /**
     * The run ends.
     *
     * @param non-negative-int $executions the executions of the target in the run
     * @param non-negative-int $features   the features of the target at the end of the run
     * @param non-negative-int $findings   the new findings of the target in the run
     */
    public function end(float $time, float $discoveries, float $decayedExecutions, int $executions, float $seconds, int $features, int $findings): void
    {
        $this->discoveries       = $discoveries;
        $this->decayedExecutions = $decayedExecutions;
        $this->executions += $executions;
        $this->seconds    += $seconds;
        $this->features = $features;
        $this->findings += $findings;

        $this->addPoint($time, $this->executions, $features);
    }

    /**
     * @param non-negative-int $executions
     * @param non-negative-int $features
     */
    private function addPoint(float $time, int $executions, int $features): void
    {
        $this->series[] = [round($time, 3), $executions, $features];

        if (count($this->series) <= self::LARGEST_NUMBER_OF_POINTS) {
            return;
        }

        $last    = count($this->series) - 1;
        $thinned = [];

        foreach ($this->series as $index => $point) {
            if ($index % 2 === 0 || $index === $last) {
                $thinned[] = $point;
            }
        }

        $this->series = $thinned;
    }

    private static function nonNegativeFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        return 0.0;
    }

    /**
     * @return non-negative-int
     */
    private static function nonNegativeInt(mixed $value): int
    {
        return is_int($value) ? max(0, $value) : 0;
    }
}
