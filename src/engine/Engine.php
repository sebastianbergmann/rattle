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
use function count;
use function hash;
use function is_int;
use function microtime;
use function min;
use function unpack;
use function usort;
use Closure;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fuzzes a target: it sends batches of inputs to the executor and learns
 * from their results.
 *
 * The inputs to start with, the corpus that was stored before and imported
 * seeds, are replayed first, smallest first in the order of choice sequences
 * (shortlex), so that smaller inputs claim the features that they produce
 * before larger inputs do. After that, an input is generated from a fresh
 * seed that is derived from the seed of the run and a counter, about one
 * time in ten and always while the corpus is empty, and otherwise created by
 * mutating a corpus entry that the scheduler chooses. An input that produced
 * a feature that is new is added to the corpus, an input that is a finding
 * is passed to the finding handler. The constant operands of comparisons
 * that a result reports, whatever its outcome, are added to the dictionary.
 *
 * An input that was mutated from a corpus entry, has fewer choices than the
 * entry, produces all the features that the entry owns, and produces no
 * feature that is new, replaces the entry, so that the corpus becomes
 * smaller as fuzzing goes on.
 *
 * An input to start with that was stored in the directory of the corpus is
 * removed from it when it is rejected or produces no feature that a smaller
 * input to start with did not produce, so that the stored corpus keeps only
 * the inputs that its features need. A stored input that is a finding now is
 * kept.
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
     * The entries that the inputs that were submitted and may replace them
     * were mutated from, by the IDs of the inputs.
     *
     * @var array<non-negative-int, CorpusEntry>
     */
    private array $parents = [];
    private readonly JobIds $ids;

    /**
     * The inputs to start with that were not submitted yet, smallest first.
     *
     * @var list<list<non-negative-int>>
     */
    private array $initialInputs = [];

    /**
     * The positions of the inputs to start with among them, by the IDs of the
     * inputs.
     *
     * @var array<non-negative-int, non-negative-int>
     */
    private array $initialPositions = [];

    /**
     * The number of inputs to start with that were submitted.
     *
     * @var non-negative-int
     */
    private int $numberOfSubmittedInitialInputs = 0;

    /**
     * The results of inputs to start with that arrived before the results of
     * the inputs to start with that were submitted before them, by their
     * positions.
     *
     * @var array<non-negative-int, Result>
     */
    private array $earlyInitialResults = [];

    /**
     * The position of the input to start with whose result is processed next.
     *
     * @var non-negative-int
     */
    private int $nextInitialInput = 0;

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
     * @param ?JobIds                    $ids         hands out the IDs of the inputs, which an engine shares with the other engines that use the same executor
     */
    public function __construct(Executor $executor, Corpus $corpus, Dictionary $dictionary, Mutator $mutator, FindingHandler $findings, EngineConfiguration $configuration, ?Closure $progress = null, ?Closure $submitted = null, ?Closure $interrupted = null, ?JobIds $ids = null)
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
        $this->ids           = $ids ?? new JobIds;
    }

    /**
     * Fuzzes the target until it is done, and processes the results of the
     * inputs that were submitted before.
     *
     * @param list<list<non-negative-int>> $initialInputs the inputs to start with, which are replayed first
     *
     * @throws CorpusException
     * @throws FindingException
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function run(array $initialInputs = []): Statistics
    {
        $this->start($initialInputs);

        while (!$this->isDone()) {
            while ($this->executor->capacity() > 0 && !$this->isDone()) {
                $batch = $this->nextBatch();

                if ($batch === []) {
                    break;
                }

                $this->executor->submit($batch);
            }

            $this->process($this->executor->collect());
        }

        // the inputs that were submitted before fuzzing stopped are processed
        while ($this->hasPendingInputs()) {
            $results = $this->executor->collect();

            if ($results === [] && $this->isInterrupted()) {
                break;
            }

            $this->process($results);
        }

        return $this->statistics;
    }

    /**
     * Sets the inputs to start with, which are replayed first. Calling run()
     * does this; an engine that shares its executor with other engines is
     * started with this method and then composes batches and processes
     * results when it is asked to.
     *
     * @param list<list<non-negative-int>> $initialInputs
     */
    public function start(array $initialInputs): void
    {
        // PHP compares a list with fewer elements as smaller, and lists with
        // the same number of elements element by element, which is the
        // shortlex order of choice sequences
        usort($initialInputs, static fn (array $a, array $b): int => $a <=> $b);

        $this->initialInputs = $initialInputs;
    }

    /**
     * Whether no more inputs are submitted: because a finding was found and
     * the engine stops on a finding, because the maximum number of
     * executions or the maximum time was reached, or because fuzzing was
     * interrupted.
     *
     * @phpstan-impure
     */
    public function isDone(): bool
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

    public function hasPendingInputs(): bool
    {
        return $this->pending !== [];
    }

    public function statistics(): Statistics
    {
        return $this->statistics;
    }

    /**
     * Composes the next batch of inputs, which is empty when the engine has
     * submitted as many inputs as it may.
     *
     * @return list<Job>
     */
    public function nextBatch(): array
    {
        $size = $this->configuration->batchSize();

        if ($this->configuration->maximumNumberOfExecutions() !== null) {
            $size = min($size, $this->configuration->maximumNumberOfExecutions() - $this->numberOfSubmittedInputs);
        }

        $batch = [];

        for ($i = 0; $i < $size; $i++) {
            $id = $this->ids->next();

            $this->numberOfSubmittedInputs++;

            if ($this->initialInputs !== []) {
                // all features of an input to start with are needed to tell
                // whether a smaller input produced them all
                $job = Job::replay($id, array_shift($this->initialInputs))->withAllFeatures();

                $this->initialPositions[$id] = $this->numberOfSubmittedInitialInputs++;
            } elseif ($this->corpus->isEmpty() || $this->randomizer->nextFloat() < EngineConfiguration::PROBABILITY_OF_GENERATION) {
                $job = Job::generate($id, self::seedOf($this->configuration->seed(), $this->numberOfGeneratedInputs++));
            } else {
                $entry   = $this->scheduler->choose($this->randomizer);
                $choices = $this->mutator->mutate($entry, $this->randomizer);
                $job     = Job::replay($id, $choices);

                // a mutation with fewer choices than the entry it was mutated
                // from replaces the entry when it produces all the features
                // that the entry owns
                if (count($choices) < $entry->length()) {
                    $job = $job->withRequiredFeatures($entry->newFeatures());

                    $this->parents[$id] = $entry;
                }
            }

            if ($this->configuration->target() !== null) {
                $job = $job->withTarget($this->configuration->target());
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
     * Processes the results of inputs that the engine submitted.
     *
     * @param list<Result> $results
     *
     * @throws CorpusException
     */
    public function process(array $results): void
    {
        foreach ($results as $result) {
            $position = $this->initialPositions[$result->id()] ?? null;

            if ($position === null) {
                $this->processResult($result, false);

                continue;
            }

            unset($this->initialPositions[$result->id()]);

            // the results of the inputs to start with are processed in the
            // order in which the inputs were submitted, smallest first, also
            // when several workers return them in another order
            $this->earlyInitialResults[$position] = $result;

            while (isset($this->earlyInitialResults[$this->nextInitialInput])) {
                $this->processResult($this->earlyInitialResults[$this->nextInitialInput], true);

                unset($this->earlyInitialResults[$this->nextInitialInput]);

                $this->nextInitialInput++;
            }
        }

        $this->statistics->features      = $this->corpus->numberOfFeatures();
        $this->statistics->corpusEntries = $this->corpus->numberOfEntries();
        $this->statistics->tokens        = $this->dictionary->numberOfTokens();

        if ($this->progress !== null) {
            ($this->progress)($this->statistics);
        }
    }

    /**
     * @param bool $initial whether the result is the one of an input to start with
     *
     * @throws CorpusException
     */
    private function processResult(Result $result, bool $initial): void
    {
        $job = $this->pending[$result->id()] ?? null;

        assert($job !== null);

        $parent = $this->parents[$result->id()] ?? null;

        unset($this->pending[$result->id()], $this->parents[$result->id()]);

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

            return;
        }

        if ($result->outcome() === Outcome::Rejected) {
            $this->statistics->rejected++;

            if ($initial) {
                $this->removeStoredInput($job);
            }

            return;
        }

        $entry = $this->corpus->add($result->sequence(), $result->features());

        // an input to start with that produced no feature that a smaller one
        // did not produce is removed from the directory of the corpus, and so
        // is one that consumed fewer choices than it has, because the choices
        // that it consumed are stored instead
        if ($initial && ($entry === null || $entry->sequence()->choices() !== $job->choices())) {
            $this->removeStoredInput($job);
        }

        if ($entry === null && $parent !== null && $result->producedRequiredFeatures() &&
            $this->corpus->replace($parent->index(), $result->sequence())) {
            $this->statistics->reducedEntries++;
        }
    }

    /**
     * @throws CorpusException
     */
    private function removeStoredInput(Job $job): void
    {
        $choices = $job->choices();

        assert($choices !== null);

        if ($this->corpus->remove($choices)) {
            $this->statistics->removedInputs++;
        }
    }

    /**
     * @phpstan-impure
     */
    private function isInterrupted(): bool
    {
        return $this->interrupted !== null && ($this->interrupted)();
    }
}
