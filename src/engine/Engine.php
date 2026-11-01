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

use function array_shift;
use function assert;
use function hash;
use function is_int;
use function microtime;
use function min;
use function unpack;
use Closure;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fuzzes a target: it sends batches of inputs to the executor and learns
 * from their results.
 *
 * The inputs to start with, the corpus that was stored before and imported
 * seeds, are replayed first. After that, an input is generated from a fresh
 * seed that is derived from the seed of the run and a counter, about one time
 * in ten and always while the corpus is empty, and otherwise created by
 * mutating a corpus entry that the scheduler chooses. An input that produced
 * a feature that is new is added to the corpus, an input that is a finding is
 * passed to the finding handler. The constant operands of comparisons that a
 * result reports, whatever its outcome, are added to the dictionary.
 *
 * All decisions are made with one random number generator that is seeded
 * with the seed of the run. A new batch is only composed when an executor is
 * idle, after the results of the batches before were processed, so that with
 * one worker a run with the same seed executes the same inputs in the same
 * order.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Engine
{
    private readonly Executor $executor;
    private readonly Corpus $corpus;
    private readonly Dictionary $dictionary;
    private readonly Scheduler $scheduler;
    private readonly Mutator $mutator;
    private readonly FindingHandler $findings;
    private readonly EngineConfiguration $configuration;
    private readonly Randomizer $randomizer;
    private readonly Statistics $statistics;

    /**
     * @var ?Closure(Statistics): void
     */
    private readonly ?Closure $progress;

    /**
     * @var ?Closure(Job): void
     */
    private readonly ?Closure $submitted;

    /**
     * @var ?Closure(): bool
     */
    private readonly ?Closure $interrupted;

    /**
     * The inputs that were submitted and whose results were not processed.
     *
     * @var array<non-negative-int, Job>
     */
    private array $pending = [];

    /**
     * @var non-negative-int
     */
    private int $numberOfSubmittedInputs = 0;

    /**
     * @var non-negative-int
     */
    private int $numberOfGeneratedInputs = 0;
    private bool $stopped                = false;

    /**
     * Derives the seed of the n-th input that is generated from the seed of
     * the run.
     */
    public static function seedOf(int $seed, int $n): int
    {
        $unpacked = unpack('J', hash('xxh64', $seed . ':' . $n, true));

        assert($unpacked !== false && isset($unpacked[1]) && is_int($unpacked[1]));

        return $unpacked[1];
    }

    /**
     * @param ?Closure(Statistics): void $progress    is called after results were processed
     * @param ?Closure(Job): void        $submitted   is called for every input that is submitted
     * @param ?Closure(): bool           $interrupted tells whether fuzzing was interrupted, by Ctrl+C for instance
     */
    public function __construct(Executor $executor, Corpus $corpus, Dictionary $dictionary, Mutator $mutator, FindingHandler $findings, EngineConfiguration $configuration, ?Closure $progress = null, ?Closure $submitted = null, ?Closure $interrupted = null)
    {
        $this->executor      = $executor;
        $this->corpus        = $corpus;
        $this->dictionary    = $dictionary;
        $this->scheduler     = new Scheduler($corpus);
        $this->mutator       = $mutator;
        $this->findings      = $findings;
        $this->configuration = $configuration;
        $this->randomizer    = new Randomizer(new Xoshiro256StarStar($configuration->seed()));
        $this->statistics    = new Statistics;
        $this->progress      = $progress;
        $this->submitted     = $submitted;
        $this->interrupted   = $interrupted;
    }

    /**
     * @param list<list<non-negative-int>> $initialInputs the inputs to start with, which are replayed first
     */
    public function run(array $initialInputs = []): Statistics
    {
        while (!$this->shouldStop()) {
            while ($this->executor->capacity() > 0 && !$this->shouldStop()) {
                $batch = $this->nextBatch($initialInputs);

                if ($batch === []) {
                    break;
                }

                $this->executor->submit($batch);
            }

            $this->process($this->executor->collect());
        }

        // the inputs that were submitted before fuzzing stopped are processed
        while ($this->pending !== []) {
            $results = $this->executor->collect();

            if ($results === [] && $this->isInterrupted()) {
                break;
            }

            $this->process($results);
        }

        return $this->statistics;
    }

    public function statistics(): Statistics
    {
        return $this->statistics;
    }

    /**
     * @param list<list<non-negative-int>> $initialInputs
     *
     * @return list<Job>
     */
    private function nextBatch(array &$initialInputs): array
    {
        $size = $this->configuration->batchSize();

        if ($this->configuration->maximumNumberOfExecutions() !== null) {
            $size = min($size, $this->configuration->maximumNumberOfExecutions() - $this->numberOfSubmittedInputs);
        }

        $batch = [];

        for ($i = 0; $i < $size; $i++) {
            $id = $this->numberOfSubmittedInputs++;

            if ($initialInputs !== []) {
                $job = Job::replay($id, array_shift($initialInputs));
            } elseif ($this->corpus->isEmpty() || $this->randomizer->nextFloat() < EngineConfiguration::PROBABILITY_OF_GENERATION) {
                $job = Job::generate($id, self::seedOf($this->configuration->seed(), $this->numberOfGeneratedInputs++));
            } else {
                $job = Job::replay($id, $this->mutator->mutate($this->scheduler->choose($this->randomizer), $this->randomizer));
            }

            $this->pending[$id] = $job;
            $batch[]            = $job;

            if ($this->submitted !== null) {
                ($this->submitted)($job);
            }
        }

        return $batch;
    }

    /**
     * @param list<Result> $results
     */
    private function process(array $results): void
    {
        foreach ($results as $result) {
            $job = $this->pending[$result->id()] ?? null;

            assert($job !== null);

            unset($this->pending[$result->id()]);

            $this->statistics->executions++;

            $this->dictionary->addFoundTokens($result->tokens());

            if ($result->isFinding()) {
                if ($this->findings->handle($result, $job)) {
                    $this->statistics->findings++;

                    if ($this->configuration->stopOnFinding()) {
                        $this->stopped = true;
                    }
                } else {
                    $this->statistics->duplicateFindings++;
                }

                continue;
            }

            if ($result->outcome() === Outcome::Rejected) {
                $this->statistics->rejected++;

                continue;
            }

            $this->corpus->add($result->sequence(), $result->features());
        }

        $this->statistics->features      = $this->corpus->numberOfFeatures();
        $this->statistics->corpusEntries = $this->corpus->numberOfEntries();
        $this->statistics->tokens        = $this->dictionary->numberOfTokens();

        if ($this->progress !== null) {
            ($this->progress)($this->statistics);
        }
    }

    /**
     * @phpstan-impure
     */
    private function shouldStop(): bool
    {
        if ($this->stopped || $this->isInterrupted()) {
            return true;
        }

        if ($this->configuration->maximumNumberOfExecutions() !== null &&
            $this->numberOfSubmittedInputs >= $this->configuration->maximumNumberOfExecutions()) {
            return true;
        }

        return $this->configuration->maximumTime() !== null &&
               $this->statistics->elapsed(microtime(true)) >= $this->configuration->maximumTime();
    }

    /**
     * @phpstan-impure
     */
    private function isInterrupted(): bool
    {
        return $this->interrupted !== null && ($this->interrupted)();
    }
}
