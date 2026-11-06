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
use function array_map;
use function array_merge;
use function array_reverse;
use function array_shift;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function str_contains;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Encoder;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(Engine::class)]
#[CoversClass(EngineConfiguration::class)]
#[CoversClass(Statistics::class)]
#[CoversClass(Origin::class)]
#[UsesClass(CoveredPaths::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(Mutant::class)]
#[UsesClass(NewFeatures::class)]
#[UsesClass(ValueExporter::class)]
#[UsesClass(ArithmeticChoice::class)]
#[UsesClass(BoundaryChoice::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(RandomElement::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(CrossOver::class)]
#[UsesClass(DeleteChoices::class)]
#[UsesClass(DeleteSpan::class)]
#[UsesClass(DuplicateSpan::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Features::class)]
#[UsesClass(InProcessExecutor::class)]
#[UsesClass(Input::class)]
#[UsesClass(InputRejectedException::class)]
#[UsesClass(InsertChoices::class)]
#[UsesClass(AddToken::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Mutator::class)]
#[UsesClass(Dictionary::class)]
#[UsesClass(JobIds::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(RandomChoice::class)]
#[UsesClass(Result::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(SwapSpans::class)]
#[UsesClass(Target::class)]
#[Small]
final class EngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Edges::reset();
    }

    public function testFindsAMagicHeaderWithADictionary(): void
    {
        $findings = $this->findings();
        $engine   = $this->engine($this->magicHeader(), $findings, new EngineConfiguration(1, 1000, null, true), ['RATL']);

        $statistics = $engine->run();

        $this->assertSame(1, $statistics->findings);
        $this->assertLessThan(1000, $statistics->executions);
        $this->assertStringStartsWith('LogicException at ', $findings->results[0]?->failure()?->describe() ?? '');
    }

    public function testFindsAMagicHeaderWithATokenThatWasFoundInAComparison(): void
    {
        $findings = $this->findings();
        $engine   = $this->engine($this->magicHeader(), $findings, new EngineConfiguration(1, 1000, null, true), [], null, null, $this->executorThatFindsTokens($this->executor($this->magicHeader()), ['RATL']));

        $statistics = $engine->run();

        $this->assertSame(1, $statistics->findings);
        $this->assertLessThan(1000, $statistics->executions);
        $this->assertSame(1, $statistics->tokens);
    }

    public function testFindsAMagicHeaderWithoutADictionary(): void
    {
        $engine = $this->engine($this->magicHeader(), $this->findings(), new EngineConfiguration(1, 50000, null, true));

        $this->assertSame(1, $engine->run()->findings);
    }

    public function testExecutesTheSameInputsInTheSameOrderForTheSameSeed(): void
    {
        $first  = $this->submittedInputs(42);
        $second = $this->submittedInputs(42);
        $other  = $this->submittedInputs(43);

        $this->assertCount(2000, $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
    }

    public function testExecutesTheSameInputsInTheSameOrderWithAnExecutionLog(): void
    {
        $log = $this->log();

        $this->assertSame($this->submittedInputs(42), $this->submittedInputs(42, $log));
        $this->assertCount(2000, $log->executions);
    }

    public function testTellsTheExecutionLogWhereEveryInputCameFromAndWhatItDidWithItsResult(): void
    {
        $log        = $this->log();
        $corpus     = new Corpus;
        $dictionary = new Dictionary(['RATL']);
        $engine     = new Engine(
            $this->executor($this->magicHeader()),
            $corpus,
            $dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 3000, null, true),
            null,
            null,
            null,
            null,
            null,
            $log,
        );

        $statistics = $engine->run([BytesEncoding::choicesFor('x', 64)]);
        $kinds      = [];
        $added      = [];
        $found      = false;

        $this->assertSame(1, $statistics->findings);
        $this->assertCount($statistics->executions, $log->executions);

        foreach ($log->executions as [$job, $result, $origin, $entry, , $finding]) {
            $this->assertSame($job->id(), $result->id());
            $this->assertTrue($job->reportCoverage());
            $this->assertTrue($job->reportDrawnValues());
            $this->assertNotNull($result->path());
            $this->assertNotNull($result->drawnValues());

            $kinds[$origin->kind()] = true;

            if ($origin->kind() === Origin::MUTATED) {
                $this->assertArrayHasKey((int) $origin->parent(), $corpus->entries());
            } else {
                $this->assertNull($origin->parent());
                $this->assertSame([], $origin->mutations());
            }

            if ($entry !== null) {
                $added[] = $entry->index();
            }

            if ($result->isFinding()) {
                // the finding handler knows every finding after the first
                $this->assertSame(!$found, $finding);

                $found = true;
            } else {
                $this->assertNull($finding);
            }
        }

        $first = $log->executions[0] ?? null;

        $this->assertTrue($found);

        $this->assertNotNull($first);
        $this->assertSame(Origin::INITIAL, $first[2]->kind());
        $this->assertSame(["'x'"], $first[1]->drawnValues());
        $this->assertSame([Origin::INITIAL => true, Origin::GENERATED => true, Origin::MUTATED => true], $kinds);
        $this->assertSame(array_keys($corpus->entries()), $added);
        $this->assertContains('AddToken', array_merge(...array_map(static fn (array $execution): array => $execution[2]->mutations(), $log->executions)));
    }

    public function testTellsTheExecutionLogWhichInputsWereRejectedAndWhichFindingsWereKnown(): void
    {
        $log    = $this->log();
        $engine = new Engine(
            new InProcessExecutor(
                new Runner(
                    Target::bytes('target', static function (string $bytes): void
                    {
                        if ($bytes === '') {
                            throw new InputRejectedException;
                        }

                        throw new LogicException;
                    }, 10),
                    100,
                    0,
                    false,
                ),
            ),
            $corpus     = new Corpus,
            $dictionary = new Dictionary,
            Mutator::create($corpus, $dictionary, 100),
            $this->findings(),
            new EngineConfiguration(1, 100),
            null,
            null,
            null,
            null,
            null,
            $log,
        );

        $statistics = $engine->run();
        $findings   = [];
        $rejected   = 0;

        foreach ($log->executions as [, $result, , $entry, , $finding]) {
            $this->assertNull($entry);

            if ($result->outcome() === Outcome::Rejected) {
                $this->assertNull($finding);

                $rejected++;

                continue;
            }

            $findings[] = $finding;
        }

        $this->assertSame($statistics->rejected, $rejected);
        $this->assertSame([true], array_values(array_unique(array_slice($findings, 0, 1))));
        $this->assertSame([false], array_values(array_unique(array_slice($findings, 1))));
    }

    public function testTellsTheExecutionLogWhichCorpusEntriesWereReplacedAndWhichFindingsTheCheckFound(): void
    {
        $log        = $this->log();
        $corpus     = new Corpus;
        $dictionary = new Dictionary;
        $checks     = 0;
        $engine     = new Engine(
            $this->executor(
                static function (string $bytes): void
                {
                    Edges::through(1, null);

                    if (str_contains($bytes, 'Z')) {
                        Edges::through(2, null);
                    }
                },
            ),
            $corpus,
            $dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 3000),
            null,
            null,
            null,
            null,
            static function (Result $result, Job $job) use (&$checks): ?bool
            {
                // the first check finds something new
                return ++$checks === 1 ? true : null;
            },
            $log,
        );

        $statistics = $engine->run([BytesEncoding::choicesFor(str_repeat('x', 20) . 'Z', 64)]);
        $replaced   = [];

        foreach ($log->executions as [, $result, $origin, $entry, $replacedEntry, $finding]) {
            if ($replacedEntry !== null) {
                $this->assertSame(Origin::MUTATED, $origin->kind());
                $this->assertSame($origin->parent(), $replacedEntry->index());
                $this->assertSame($result->sequence()->choices(), $replacedEntry->sequence()->choices());

                $replaced[] = $replacedEntry;
            }

            $this->assertSame($entry !== null && $entry->index() === 0 ? true : null, $finding);
        }

        $this->assertGreaterThan(0, $statistics->reducedEntries);
        $this->assertCount($statistics->reducedEntries, $replaced);
    }

    public function testReplaysTheInitialInputsFirstAndSmallestFirst(): void
    {
        $submitted = [];
        $engine    = $this->engine(
            $this->magicHeader(),
            $this->findings(),
            new EngineConfiguration(1, 20, null, false, 4),
            [],
            static function (Job $job) use (&$submitted): void
            {
                $submitted[] = $job;
            },
        );

        $engine->run([[1, 0x52, 0], [1, 0x41, 0], [0]]);

        $this->assertSame([[0], [1, 0x41, 0], [1, 0x52, 0]], array_map(static fn (Job $job): ?array => $job->choices(), array_slice($submitted, 0, 3)));
        $this->assertCount(20, $submitted);
        $this->assertGreaterThanOrEqual(1, $engine->statistics()->corpusEntries);
    }

    public function testRemovesStoredInputsThatProduceNoFeatureThatASmallerInputDoesNotProduce(): void
    {
        $directory = sys_get_temp_dir() . '/rattle-engine-' . uniqid();
        $inputs    = ['A' => [1, 0x41, 0], 'AA' => [1, 0x41, 1, 0x41, 0], 'B' => [1, 0x42, 0], 'C' => [1, 0x43, 0]];

        foreach ($inputs as $choices) {
            $this->store($directory, $choices);
        }

        $statistics = $this->engineWithStoredCorpus($directory)->run(array_values($inputs));

        try {
            // "AA" and "B" produce no feature that "A" does not produce, and
            // "C" is a finding, which is kept; the results arrive in reverse
            // order, but are processed smallest first
            $this->assertSame(2, $statistics->removedInputs);
            $this->assertFileExists($this->pathOf($directory, $inputs['A']));
            $this->assertFileDoesNotExist($this->pathOf($directory, $inputs['AA']));
            $this->assertFileDoesNotExist($this->pathOf($directory, $inputs['B']));
            $this->assertFileExists($this->pathOf($directory, $inputs['C']));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testProcessesTheResultsOfTheInputsToStartWithSmallestFirstWhateverTheirIds(): void
    {
        $directory = sys_get_temp_dir() . '/rattle-engine-' . uniqid();
        $inputs    = ['A' => [1, 0x41, 0], 'AA' => [1, 0x41, 1, 0x41, 0]];
        $ids       = new JobIds;

        foreach ($inputs as $choices) {
            $this->store($directory, $choices);
        }

        // other engines that share the IDs handed out the first ones
        $ids->next();
        $ids->next();

        $statistics = $this->engineWithStoredCorpus($directory, $ids)->run(array_values($inputs));

        try {
            $this->assertSame(1, $statistics->removedInputs);
            $this->assertFileExists($this->pathOf($directory, $inputs['A']));
            $this->assertFileDoesNotExist($this->pathOf($directory, $inputs['AA']));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testRemovesStoredInputsThatAreRejected(): void
    {
        $directory = sys_get_temp_dir() . '/rattle-engine-' . uniqid();
        $inputs    = ['A' => [1, 0x41, 0], 'R' => [1, 0x52, 0]];

        foreach ($inputs as $choices) {
            $this->store($directory, $choices);
        }

        $statistics = $this->engineWithStoredCorpus($directory)->run(array_values($inputs));

        try {
            $this->assertSame(1, $statistics->rejected);
            $this->assertSame(1, $statistics->removedInputs);
            $this->assertFileExists($this->pathOf($directory, $inputs['A']));
            $this->assertFileDoesNotExist($this->pathOf($directory, $inputs['R']));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testTellsWhetherTheInputsToStartWithWereSubmittedAndWhetherTheirResultsWereProcessed(): void
    {
        $executor = $this->executor($this->magicHeader());
        $engine   = $this->engine($this->magicHeader(), $this->findings(), new EngineConfiguration(1, null, null, false, 1), [], null, null, $executor);

        $engine->start([[1, 0x41, 0]]);

        $this->assertTrue($engine->hasInputsToStartWith());
        $this->assertFalse($engine->hasReplayedInputsToStartWith());

        $batch = $engine->nextBatch();
        $job   = $batch[0] ?? null;

        $this->assertCount(1, $batch);
        $this->assertNotNull($job);

        $executor->submit([$job]);

        $this->assertFalse($engine->hasInputsToStartWith());
        $this->assertFalse($engine->hasReplayedInputsToStartWith());

        $engine->process($executor->collect());

        $this->assertTrue($engine->hasReplayedInputsToStartWith());
    }

    public function testComposesBatchesForThePropertyThatItFuzzesWithIdsFromTheIdsThatItShares(): void
    {
        $ids    = new JobIds;
        $corpus = new Corpus;
        $engine = new Engine(
            $this->executor(static function (string $bytes): void
            {
            }),
            $corpus,
            $dictionary = new Dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 3, null, false, 2, 'Vendor\\ParserTest::testRoundTrip'),
            null,
            null,
            null,
            $ids,
        );

        $ids->next();

        $engine->start([[0]]);

        $first  = $engine->nextBatch();
        $second = $engine->nextBatch();

        $this->assertSame([1, 2], array_map(static fn (Job $job): int => $job->id(), $first));
        $this->assertSame([3], array_map(static fn (Job $job): int => $job->id(), $second));
        $this->assertSame([0], ($first[0] ?? null)?->choices());
        $this->assertSame(['Vendor\\ParserTest::testRoundTrip'], array_values(array_unique(array_map(static fn (Job $job): ?string => $job->target(), [...$first, ...$second]))));
        $this->assertSame([], $engine->nextBatch());
        $this->assertTrue($engine->isDone());
        $this->assertTrue($engine->hasPendingInputs());

        $engine->process([new Result(1, Outcome::Passed, null, new ChoiceSequence([0]), [1], null)]);

        $this->assertSame(1, $engine->statistics()->executions);
        $this->assertSame(1, $engine->statistics()->corpusEntries);
    }

    public function testReplacesAStoredInputWithTheChoicesThatItConsumed(): void
    {
        $directory = sys_get_temp_dir() . '/rattle-engine-' . uniqid();

        $this->store($directory, [1, 0x41, 0, 7]);

        $statistics = $this->engineWithStoredCorpus($directory)->run([[1, 0x41, 0, 7]]);

        try {
            $this->assertSame(1, $statistics->removedInputs);
            $this->assertFileDoesNotExist($this->pathOf($directory, [1, 0x41, 0, 7]));
            $this->assertFileExists($this->pathOf($directory, [1, 0x41, 0]));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testReplacesACorpusEntryWithAShorterInputThatProducesTheFeaturesThatTheEntryOwns(): void
    {
        $corpus     = new Corpus;
        $dictionary = new Dictionary;
        $engine     = new Engine(
            $this->executor(
                static function (string $bytes): void
                {
                    Edges::through(1, null);

                    if (str_contains($bytes, 'Z')) {
                        Edges::through(2, null);
                    }
                },
            ),
            $corpus,
            $dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 3000),
        );

        $long = BytesEncoding::choicesFor(str_repeat('x', 20) . 'Z', 64);

        $statistics = $engine->run([$long]);
        $entry      = $corpus->entries()[0] ?? null;

        $this->assertNotNull($entry);
        $this->assertGreaterThan(0, $statistics->reducedEntries);
        $this->assertLessThan(count($long), $entry->length());
        $this->assertStringContainsString('Z', BytesEncoding::bytesFor($entry->sequence()->choices(), 64));
    }

    public function testGeneratesInputsWhileTheCorpusIsEmpty(): void
    {
        $submitted = [];
        $engine    = $this->engine(
            static fn (string $bytes) => null,
            $this->findings(),
            new EngineConfiguration(7, 32),
            [],
            static function (Job $job) use (&$submitted): void
            {
                $submitted[] = $job;
            },
        );

        $statistics = $engine->run();

        // without instrumented code, no input produces a feature
        $this->assertSame(0, $statistics->corpusEntries);
        $this->assertSame(32, $statistics->executions);

        foreach ($submitted as $n => $job) {
            $this->assertFalse($job->isReplay());
            $this->assertSame(Engine::seedOf(7, $n), $job->seed());
        }
    }

    public function testDerivesTheSeedsOfGeneratedInputsFromTheSeedOfTheRun(): void
    {
        $this->assertSame(Engine::seedOf(1, 0), Engine::seedOf(1, 0));
        $this->assertNotSame(Engine::seedOf(1, 0), Engine::seedOf(1, 1));
        $this->assertNotSame(Engine::seedOf(1, 0), Engine::seedOf(2, 0));
    }

    public function testCountsRejectedInputsAndDuplicateFindings(): void
    {
        $findings = $this->findings();
        $progress = 0;
        $engine   = new Engine(
            $executor = new InProcessExecutor(
                new Runner(
                    Target::bytes('target', static function (string $bytes): void
                    {
                        if ($bytes === '') {
                            throw new InputRejectedException;
                        }

                        throw new LogicException;
                    }, 10),
                    100,
                    0,
                    false,
                ),
            ),
            $corpus     = new Corpus,
            $dictionary = new Dictionary,
            Mutator::create($corpus, $dictionary, 100),
            $findings,
            new EngineConfiguration(1, 100),
            static function (Statistics $statistics) use (&$progress): void
            {
                $progress++;
            },
        );

        $statistics = $engine->run();

        $this->assertSame(100, $statistics->executions);
        $this->assertGreaterThan(0, $statistics->rejected);
        $this->assertSame(1, $statistics->findings);
        $this->assertSame(100 - $statistics->rejected - 1, $statistics->duplicateFindings);
        $this->assertGreaterThan(0, $progress);
        $this->assertGreaterThan(0.0, $statistics->elapsed());
        $this->assertGreaterThan(0.0, $statistics->executionsPerSecond());
        $this->assertSame(1, $executor->capacity());
    }

    public function testChecksTheInputsThatEnterTheCorpusAndTheNewFindingsFurtherAndCountsWhatTheCheckFinds(): void
    {
        $checked = [];
        $engine  = new Engine(
            $this->executor(static function (string $bytes): void
            {
                Edges::through(1 + strlen($bytes) % 4, null);

                if (strlen($bytes) === 6) {
                    throw new LogicException;
                }
            }),
            $corpus     = new Corpus,
            $dictionary = new Dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 300),
            null,
            null,
            null,
            null,
            static function (Result $result, Job $job) use (&$checked): ?bool
            {
                $checked[] = $result->outcome();

                // the second check finds something new, the third something
                // known, the others nothing
                return match (count($checked)) {
                    2       => true,
                    3       => false,
                    default => null,
                };
            },
        );

        $statistics = $engine->run();

        $this->assertCount($corpus->numberOfEntries() + $statistics->findings - 1, $checked);
        $this->assertContains(Outcome::Failed, $checked);
        $this->assertSame(2, $statistics->findings);
        $this->assertGreaterThanOrEqual(1, $statistics->duplicateFindings);
    }

    public function testStopsWhenInterrupted(): void
    {
        $engine = $this->engine(
            $this->magicHeader(),
            $this->findings(),
            new EngineConfiguration(1),
            [],
            null,
            static fn (): bool => true,
        );

        $this->assertSame(0, $engine->run()->executions);
    }

    public function testProcessesTheResultsOfTheInputsThatWereSubmittedBeforeFuzzingStopped(): void
    {
        $engine = $this->engine(
            $this->magicHeader(),
            $this->findings(),
            new EngineConfiguration(1, 4, null, false, 2),
            [],
            null,
            null,
            $this->executorThatReturnsOneResultAtATime($this->executor($this->magicHeader())),
        );

        $this->assertSame(4, $engine->run()->executions);
        $this->assertFalse($engine->hasPendingInputs());
    }

    public function testStopsWaitingForTheResultsOfTheInputsThatWereSubmittedWhenInterrupted(): void
    {
        $interrupted = false;
        $engine      = $this->engine(
            $this->magicHeader(),
            $this->findings(),
            new EngineConfiguration(1, 4),
            [],
            null,
            static function () use (&$interrupted): bool
            {
                return $interrupted;
            },
            $this->executorWithoutResults(
                $this->executor($this->magicHeader()),
                static function () use (&$interrupted): void
                {
                    $interrupted = true;
                },
            ),
        );

        $this->assertSame(0, $engine->run()->executions);
        $this->assertTrue($engine->hasPendingInputs());
    }

    public function testStopsWhenTheTimeIsUp(): void
    {
        $engine = $this->engine($this->magicHeader(), $this->findings(), new EngineConfiguration(1, null, 0.05));

        $this->assertGreaterThan(0, $engine->run()->executions);
    }

    /**
     * @return list<array{0: ?list<non-negative-int>, 1: ?int}>
     */
    private function submittedInputs(int $seed, ?ExecutionLog $log = null): array
    {
        $submitted = [];

        $this->engine(
            $this->magicHeader(),
            $this->findings(),
            new EngineConfiguration($seed, 2000),
            ['RA'],
            static function (Job $job) use (&$submitted): void
            {
                $submitted[] = [$job->choices(), $job->seed()];
            },
            null,
            null,
            $log,
        )->run();

        return $submitted;
    }

    /**
     * @return Closure(string): void
     */
    private function magicHeader(): Closure
    {
        return static function (string $bytes): void
        {
            Edges::through(1, null);

            if (($bytes[0] ?? '') === 'R') {
                Edges::through(2, null);

                if (($bytes[1] ?? '') === 'A') {
                    Edges::through(3, null);

                    if (($bytes[2] ?? '') === 'T') {
                        Edges::through(4, null);

                        if (($bytes[3] ?? '') === 'L') {
                            throw new LogicException('magic header');
                        }
                    }
                }
            }
        };
    }

    /**
     * @param Closure(string): void  $target
     * @param list<non-empty-string> $tokens      the tokens of the dictionary file
     * @param ?Closure(Job): void    $submitted
     * @param ?Closure(): bool       $interrupted
     */
    private function engine(Closure $target, FindingHandler $findings, EngineConfiguration $configuration, array $tokens = [], ?Closure $submitted = null, ?Closure $interrupted = null, ?Executor $executor = null, ?ExecutionLog $log = null): Engine
    {
        $corpus     = new Corpus;
        $dictionary = new Dictionary($tokens);

        return new Engine(
            $executor ?? $this->executor($target),
            $corpus,
            $dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $findings,
            $configuration,
            null,
            $submitted,
            $interrupted,
            null,
            null,
            $log,
        );
    }

    /**
     * An engine that only replays the inputs to start with, with an executor
     * that returns the results of each batch in reverse order. Its target
     * takes the same edges for every string that contains "A", throws for
     * "C", rejects "R", and takes fewer edges for every other string.
     */
    private function engineWithStoredCorpus(string $directory, ?JobIds $ids = null): Engine
    {
        $corpus     = new Corpus($directory);
        $dictionary = new Dictionary;
        $executor   = $this->executor(
            static function (string $bytes): void
            {
                Edges::through(1, null);

                if (str_contains($bytes, 'A')) {
                    Edges::through(2, null);
                }

                if ($bytes === 'C') {
                    throw new LogicException;
                }

                if ($bytes === 'R') {
                    throw InputRejectedException::byTarget();
                }
            },
        );

        return new Engine(
            new readonly class($executor) implements Executor
            {
                public function __construct(private Executor $executor)
                {
                }

                public function target(): TargetDescription
                {
                    return $this->executor->target();
                }

                public function capacity(): int
                {
                    return $this->executor->capacity();
                }

                public function submit(array $jobs): void
                {
                    $this->executor->submit($jobs);
                }

                public function collect(): array
                {
                    return array_reverse($this->executor->collect());
                }

                public function execute(Job $job): Result
                {
                    return $this->executor->execute($job);
                }

                public function shutdown(): void
                {
                    $this->executor->shutdown();
                }
            },
            $corpus,
            $dictionary,
            Mutator::create($corpus, $dictionary, 8192),
            $this->findings(),
            new EngineConfiguration(1, 4, null, false, 4),
            null,
            null,
            null,
            $ids,
        );
    }

    /**
     * @param list<non-negative-int> $choices
     */
    private function store(string $directory, array $choices): void
    {
        if (!is_dir($directory)) {
            mkdir($directory);
        }

        file_put_contents($this->pathOf($directory, $choices), (new Encoder)->encode(new ChoiceSequence($choices)));
    }

    /**
     * @param list<non-negative-int> $choices
     */
    private function pathOf(string $directory, array $choices): string
    {
        return $directory . '/' . Corpus::fileNameOf((new Encoder)->encode(new ChoiceSequence($choices)));
    }

    private function removeDirectory(string $directory): void
    {
        foreach ((array) scandir($directory) as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($directory . '/' . $file);
            }
        }

        rmdir($directory);
    }

    /**
     * An executor that accepts any number of inputs and returns the result
     * of one of them each time its results are collected.
     */
    private function executorThatReturnsOneResultAtATime(Executor $executor): Executor
    {
        return new class($executor) implements Executor
        {
            /**
             * @var list<Job>
             */
            private array $pending = [];

            public function __construct(private readonly Executor $executor)
            {
            }

            public function target(): TargetDescription
            {
                return $this->executor->target();
            }

            public function capacity(): int
            {
                return 1;
            }

            public function submit(array $jobs): void
            {
                foreach ($jobs as $job) {
                    $this->pending[] = $job;
                }
            }

            public function collect(): array
            {
                $job = array_shift($this->pending);

                return $job === null ? [] : [$this->executor->execute($job)];
            }

            public function execute(Job $job): Result
            {
                return $this->executor->execute($job);
            }

            public function shutdown(): void
            {
            }
        };
    }

    /**
     * An executor that accepts inputs and never returns their results, as
     * workers that hang, and that calls a closure when its results are
     * collected.
     *
     * @param Closure(): void $collected
     */
    private function executorWithoutResults(Executor $executor, Closure $collected): Executor
    {
        return new readonly class($executor, $collected) implements Executor
        {
            /**
             * @param Closure(): void $collected
             */
            public function __construct(private Executor $executor, private Closure $collected)
            {
            }

            public function target(): TargetDescription
            {
                return $this->executor->target();
            }

            public function capacity(): int
            {
                return 1;
            }

            public function submit(array $jobs): void
            {
            }

            public function collect(): array
            {
                ($this->collected)();

                return [];
            }

            public function execute(Job $job): Result
            {
                return $this->executor->execute($job);
            }

            public function shutdown(): void
            {
            }
        };
    }

    /**
     * @param Closure(string): void $target
     */
    private function executor(Closure $target): InProcessExecutor
    {
        return new InProcessExecutor(new Runner(Target::bytes('target', $target, 64), 8192, 0, false));
    }

    /**
     * An executor that reports tokens with its first result, as a worker
     * reports the constant operands of the comparisons in the code that it
     * instrumented.
     *
     * @param non-empty-list<non-empty-string> $tokens
     */
    private function executorThatFindsTokens(Executor $executor, array $tokens): Executor
    {
        return new readonly class($executor, $tokens) implements Executor
        {
            /**
             * @param non-empty-list<non-empty-string> $tokens
             */
            public function __construct(private Executor $executor, private array $tokens)
            {
            }

            public function target(): TargetDescription
            {
                return $this->executor->target();
            }

            public function capacity(): int
            {
                return $this->executor->capacity();
            }

            public function submit(array $jobs): void
            {
                $this->executor->submit($jobs);
            }

            public function collect(): array
            {
                $results = $this->executor->collect();

                if (isset($results[0]) && $results[0]->id() === 0) {
                    $results[0] = $results[0]->withTokens($this->tokens);
                }

                return $results;
            }

            public function execute(Job $job): Result
            {
                return $this->executor->execute($job);
            }

            public function shutdown(): void
            {
                $this->executor->shutdown();
            }
        };
    }

    /**
     * @return ExecutionLog&object{executions: list<array{0: Job, 1: Result, 2: Origin, 3: ?CorpusEntry, 4: ?CorpusEntry, 5: ?bool}>}
     */
    private function log(): ExecutionLog
    {
        return new class implements ExecutionLog
        {
            /**
             * @var list<array{0: Job, 1: Result, 2: Origin, 3: ?CorpusEntry, 4: ?CorpusEntry, 5: ?bool}>
             */
            public array $executions = [];

            public function executed(Job $job, Result $result, Origin $origin, ?CorpusEntry $added, ?CorpusEntry $replaced, ?bool $finding): void
            {
                $this->executions[] = [$job, $result, $origin, $added, $replaced, $finding];
            }
        };
    }

    /**
     * @return FindingHandler&object{results: list<?Result>}
     */
    private function findings(): FindingHandler
    {
        return new class implements FindingHandler
        {
            /**
             * @var list<?Result>
             */
            public array $results = [];

            public function handle(Result $result, Job $job): bool
            {
                $this->results[] = $result;

                return count($this->results) === 1;
            }
        };
    }
}
