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

use function array_keys;
use function assert;
use function count;
use function hash;
use function microtime;
use Closure;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fuzzes several targets, the properties of a PHPUnit test suite, together
 * with one executor whose workers serve all of them: each target has an
 * engine of its own, and whenever a worker is idle, the engine of one target
 * composes the next batch.
 *
 * The target is chosen at random, with a weight that estimates how likely
 * its next input is to produce a feature that is new for it:
 *
 *     (discoveries + 1) / (executions + 2)
 *
 * where discoveries is the number of new features that the target produced,
 * and executions is the number of its inputs that were executed. Both decay
 * by a factor of 0.9999 with every execution of the target, so that what it
 * found recently counts more than what it found long ago. A target that keeps
 * finding new features is fuzzed more often, a target whose discoveries dried
 * up is fuzzed less often, but no target gets no time at all: its weight
 * does not drop below about 1 / 10,000.
 *
 * Fuzzing ends when the engines of all targets are done, when the maximum
 * time has passed, or when it is interrupted. The engine of a target is done
 * when the target reached its maximum number of executions, or found a
 * defect and fuzzing stops on a finding.
 *
 * The decayed numbers of the discoveries and executions of a target are kept
 * in its history (see TargetHistory), so that a run starts with what the runs
 * before it learned instead of with the same weight for every target. The
 * history also records when the target discovered features, and what it did
 * in the run when the run ends.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class TargetScheduler
{
    private const float DECAY = 0.9999;
    private readonly Executor $executor;

    /**
     * @var non-empty-array<non-empty-string, Engine>
     */
    private readonly array $engines;
    private readonly ?float $maximumTime;
    private readonly float $start;
    private readonly Randomizer $randomizer;

    /**
     * @var ?Closure(): bool
     */
    private readonly ?Closure $interrupted;

    /**
     * @var ?Closure(): void
     */
    private readonly ?Closure $progress;

    /**
     * The decayed number of new features that each target produced.
     *
     * @var array<non-empty-string, float>
     */
    private array $discoveries = [];

    /**
     * The decayed number of executions of each target.
     *
     * @var array<non-empty-string, float>
     */
    private array $executions = [];

    /**
     * The targets of the inputs that were submitted and whose results were
     * not processed, by their IDs.
     *
     * @var array<non-negative-int, non-empty-string>
     */
    private array $targets = [];

    /**
     * @var array<non-empty-string, TargetHistory>
     */
    private array $histories = [];

    /**
     * How likely the next input of a target is to produce a feature that is
     * new for it.
     */
    public static function weightOf(float $discoveries, float $executions): float
    {
        return ($discoveries + 1) / ($executions + 2);
    }

    /**
     * @param non-empty-array<non-empty-string, Engine> $engines     the engines of the targets, by the names of the targets, all of which use the executor and were started
     * @param ?float                                    $maximumTime in seconds, for all targets together
     * @param ?Closure(): bool                          $interrupted tells whether fuzzing was interrupted, by Ctrl+C for instance
     * @param ?Closure(): void                          $progress    is called after results were processed
     * @param array<non-empty-string, TargetHistory>    $histories   the histories of the targets, by their names, which are updated
     */
    public function __construct(Executor $executor, array $engines, int $seed, ?float $maximumTime = null, ?Closure $interrupted = null, ?Closure $progress = null, array $histories = [])
    {
        $this->executor    = $executor;
        $this->engines     = $engines;
        $this->maximumTime = $maximumTime;
        $this->start       = microtime(true);
        $this->randomizer  = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'targets ' . $seed, true)));
        $this->interrupted = $interrupted;
        $this->progress    = $progress;

        foreach (array_keys($engines) as $target) {
            $history = $histories[$target] ?? new TargetHistory($target);

            $this->histories[$target]   = $history;
            $this->discoveries[$target] = $history->discoveries();
            $this->executions[$target]  = $history->decayedExecutions();
        }
    }

    /**
     * @throws CorpusException
     * @throws FindingException
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function run(): void
    {
        foreach ($this->histories as $history) {
            $history->begin($this->start);
        }

        while (!$this->isDone()) {
            while ($this->executor->capacity() > 0 && !$this->isDone()) {
                $chosen = $this->choose();

                if ($chosen === null) {
                    break;
                }

                [$target, $engine] = $chosen;

                $batch = $engine->nextBatch();

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $job) {
                    $this->targets[$job->id()] = $target;
                }

                $this->executor->submit($batch);
            }

            $this->process($this->executor->collect());
        }

        // the inputs that were submitted before fuzzing stopped are processed
        while ($this->targets !== []) {
            $results = $this->executor->collect();

            if ($results === [] && $this->isInterrupted()) {
                break;
            }

            $this->process($results);
        }

        $this->endHistories();
    }

    /**
     * The histories of the targets, by their names.
     *
     * @return array<non-empty-string, TargetHistory>
     */
    public function histories(): array
    {
        return $this->histories;
    }

    /**
     * The engines of the targets that are not done.
     *
     * @return array<non-empty-string, Engine>
     */
    public function activeEngines(): array
    {
        $active = [];

        foreach ($this->engines as $target => $engine) {
            if (!$engine->isDone()) {
                $active[$target] = $engine;
            }
        }

        return $active;
    }

    /**
     * Chooses the target whose engine composes the next batch, or returns
     * null when the engines of all targets are done.
     *
     * @return ?array{0: non-empty-string, 1: Engine}
     */
    private function choose(): ?array
    {
        $candidates = [];
        $total      = 0.0;

        foreach ($this->activeEngines() as $target => $engine) {
            $weight = self::weightOf($this->discoveries[$target] ?? 0.0, $this->executions[$target] ?? 0.0);

            $candidates[] = ['target' => $target, 'engine' => $engine, 'weight' => $weight];
            $total += $weight;
        }

        if ($candidates === []) {
            return null;
        }

        $selector = $this->randomizer->nextFloat() * $total;

        foreach ($candidates as $candidate) {
            $selector -= $candidate['weight'];

            if ($selector < 0) {
                return [$candidate['target'], $candidate['engine']];
            }
        }

        // rounding can leave a tiny rest
        $last = $candidates[count($candidates) - 1];

        return [$last['target'], $last['engine']];
    }

    /**
     * Passes the results to the engines of their targets, and updates what
     * is known about how likely the targets are to produce new features.
     *
     * @param list<Result> $results
     *
     * @throws CorpusException
     * @throws FindingException
     * @throws ProtocolException
     * @throws WorkerException
     */
    private function process(array $results): void
    {
        $byTarget = [];

        foreach ($results as $result) {
            $target = $this->targets[$result->id()] ?? null;

            assert($target !== null);

            unset($this->targets[$result->id()]);

            $byTarget[$target][] = $result;
        }

        foreach ($byTarget as $target => $targetResults) {
            $engine = $this->engines[$target] ?? null;

            assert($engine !== null);

            $features = $engine->statistics()->features;

            $engine->process($targetResults);

            $decay       = self::DECAY ** count($targetResults);
            $statistics  = $engine->statistics();
            $discoveries = $statistics->features - $features;

            $this->discoveries[$target] = ($this->discoveries[$target] ?? 0.0) * $decay + $discoveries;
            $this->executions[$target]  = ($this->executions[$target] ?? 0.0) * $decay + count($targetResults);

            if ($discoveries > 0) {
                ($this->histories[$target] ?? null)?->discover(microtime(true), $statistics->executions, $statistics->features);
            }
        }

        if ($this->progress !== null) {
            ($this->progress)();
        }
    }

    /**
     * Records in the history of every target what it did in the run, and
     * gives every target its share of the time of the run, in proportion to
     * its executions.
     */
    private function endHistories(): void
    {
        $end        = microtime(true);
        $executions = 0;

        foreach ($this->engines as $engine) {
            $executions += $engine->statistics()->executions;
        }

        foreach ($this->engines as $target => $engine) {
            $history    = $this->histories[$target] ?? null;
            $statistics = $engine->statistics();

            if ($history === null) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $history->end(
                $end,
                $this->discoveries[$target] ?? 0.0,
                $this->executions[$target] ?? 0.0,
                $statistics->executions,
                $executions === 0 ? 0.0 : ($end - $this->start) * $statistics->executions / $executions,
                $statistics->features,
                $statistics->findings,
            );
        }
    }

    /**
     * @phpstan-impure
     */
    private function isDone(): bool
    {
        if ($this->isInterrupted()) {
            return true;
        }

        if ($this->maximumTime !== null && microtime(true) - $this->start >= $this->maximumTime) {
            return true;
        }

        return $this->activeEngines() === [];
    }

    /**
     * @phpstan-impure
     */
    private function isInterrupted(): bool
    {
        return $this->interrupted !== null && ($this->interrupted)();
    }
}
