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

use function array_map;
use function array_shift;
use function assert;
use function count;
use function str_repeat;
use function strlen;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(TargetScheduler::class)]
#[UsesClass(ArithmeticChoice::class)]
#[UsesClass(AddToken::class)]
#[UsesClass(BoundaryChoice::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(CrossOver::class)]
#[UsesClass(DeleteChoices::class)]
#[UsesClass(DeleteSpan::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(Dictionary::class)]
#[UsesClass(DuplicateSpan::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Engine::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Features::class)]
#[UsesClass(Input::class)]
#[UsesClass(InsertChoices::class)]
#[UsesClass(Job::class)]
#[UsesClass(JobIds::class)]
#[UsesClass(Location::class)]
#[UsesClass(Mutator::class)]
#[UsesClass(NewFeatures::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(RandomChoice::class)]
#[UsesClass(RandomElement::class)]
#[UsesClass(Result::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(Statistics::class)]
#[UsesClass(SwapSpans::class)]
#[UsesClass(Target::class)]
#[UsesClass(TargetHistory::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(ValueExporter::class)]
#[Small]
final class TargetSchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        Edges::reset();
    }

    public function testWeighsATargetByHowLikelyItsNextInputIsToProduceANewFeature(): void
    {
        $this->assertSame(0.5, TargetScheduler::weightOf(0.0, 0.0));
        $this->assertSame(11 / 102, TargetScheduler::weightOf(10.0, 100.0));
        $this->assertEqualsWithDelta(1 / 10002, TargetScheduler::weightOf(0.0, 10000.0), 1e-12);
    }

    public function testFuzzesATargetThatKeepsFindingNewFeaturesMoreOftenThanOneWhoseDiscoveriesDriedUp(): void
    {
        $engines = $this->engines(
            [
                // every length of the string takes another edge
                'growing' => static function (string $bytes): void
                {
                    Edges::through(1 + strlen($bytes), null);
                },
                // every string takes the same edge
                'flat' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
            ],
            null,
            false,
        );

        $growing = $engines['engines']['growing'] ?? null;
        $flat    = $engines['engines']['flat'] ?? null;

        $this->assertNotNull($growing);
        $this->assertNotNull($flat);

        $scheduler = new TargetScheduler(
            $engines['executor'],
            $engines['engines'],
            1,
            null,
            static fn (): bool => $growing->statistics()->executions + $flat->statistics()->executions >= 2000,
        );

        $scheduler->run();

        $this->assertGreaterThan(2 * $flat->statistics()->executions, $growing->statistics()->executions);
        $this->assertGreaterThan(0, $flat->statistics()->executions);
    }

    public function testStartsWithTheWeightsThatTheHistoriesOfTheTargetsKeptFromEarlierRuns(): void
    {
        $engines = $this->engines(
            [
                'saturated' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
                'new' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
            ],
            null,
            false,
        );

        $saturated = $engines['engines']['saturated'] ?? null;
        $new       = $engines['engines']['new'] ?? null;

        $this->assertNotNull($saturated);
        $this->assertNotNull($new);

        // earlier runs executed the saturated target 10,000 times without a
        // discovery
        $history = TargetHistory::fromArray(['version' => 1, 'target' => 'saturated', 'discoveries' => 0.0, 'decayedExecutions' => 10000.0]);

        $this->assertNotNull($history);

        (new TargetScheduler(
            $engines['executor'],
            $engines['engines'],
            1,
            null,
            static fn (): bool => $saturated->statistics()->executions + $new->statistics()->executions >= 2000,
            null,
            ['saturated' => $history],
        ))->run();

        $this->assertLessThan($new->statistics()->executions / 4, $saturated->statistics()->executions);
    }

    public function testRecordsWhatTheTargetsDidInTheRunInTheirHistories(): void
    {
        $engines = $this->engines(
            [
                'growing' => static function (string $bytes): void
                {
                    Edges::through(1 + strlen($bytes), null);
                },
            ],
            500,
            false,
        );

        $growing = $engines['engines']['growing'] ?? null;

        $this->assertNotNull($growing);

        $scheduler = new TargetScheduler($engines['executor'], $engines['engines'], 1);

        $scheduler->run();

        $history = $scheduler->histories()['growing'] ?? null;

        $this->assertNotNull($history);
        $this->assertSame(1, $history->runs());
        $this->assertSame(500, $history->executions());
        $this->assertSame($growing->statistics()->features, $history->features());
        $this->assertGreaterThan(0.0, $history->discoveries());
        $this->assertGreaterThan(0.0, $history->decayedExecutions());
        $this->assertNotNull($history->lastDiscovery());
        $this->assertGreaterThanOrEqual(3, count($history->series()));
    }

    public function testCountsOnlyTheFeaturesBeyondTheOnesThatATargetHadAsDiscoveriesWhenItsCorpusIsReplayed(): void
    {
        foreach ([5 => false, 3 => true] as $features => $discovered) {
            $engines = $this->engines(
                [
                    // every length of the string takes another edge
                    'growing' => static function (string $bytes): void
                    {
                        Edges::through(1 + strlen($bytes), null);
                    },
                ],
                5,
                false,
                ['growing' => ['a', 'ab', 'abc', 'abcd', 'abcde']],
            );

            $history = TargetHistory::fromArray(['version' => 1, 'target' => 'growing', 'features' => $features, 'decayedExecutions' => 100.0]);

            $this->assertNotNull($history);

            (new TargetScheduler($engines['executor'], $engines['engines'], 1, null, null, null, ['growing' => $history]))->run();

            // the corpus of five inputs reaches five features again
            $this->assertSame(5, $history->features());
            $this->assertSame($discovered, $history->lastDiscovery() !== null);
            $this->assertSame($discovered, $history->discoveries() > 1.0);

            Edges::reset();
        }
    }

    public function testReplaysTheStoredCorporaOfAllTargetsFirst(): void
    {
        $engines = $this->engines(
            [
                'saturated' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
                'new' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
            ],
            null,
            false,
            ['saturated' => ['a', 'b', 'c'], 'new' => ['d', 'e', 'f']],
        );

        $saturated = $engines['engines']['saturated'] ?? null;
        $new       = $engines['engines']['new'] ?? null;

        $this->assertNotNull($saturated);
        $this->assertNotNull($new);

        $history = TargetHistory::fromArray(['version' => 1, 'target' => 'saturated', 'decayedExecutions' => 10000.0]);

        $this->assertNotNull($history);

        (new TargetScheduler(
            $engines['executor'],
            $engines['engines'],
            1,
            null,
            static fn (): bool => $saturated->statistics()->executions + $new->statistics()->executions >= 32,
            null,
            ['saturated' => $history],
        ))->run();

        $this->assertTrue($saturated->hasReplayedInputsToStartWith());
        $this->assertTrue($new->hasReplayedInputsToStartWith());
    }

    public function testKeepsTheFeaturesOfATargetWhoseCorpusWasNotReplayedCompletely(): void
    {
        $inputs = [];

        for ($length = 1; $length <= 40; $length++) {
            $inputs[] = str_repeat('a', $length);
        }

        $engines = $this->engines(
            [
                'growing' => static function (string $bytes): void
                {
                    Edges::through(1 + strlen($bytes), null);
                },
            ],
            null,
            false,
            ['growing' => $inputs],
        );

        $growing = $engines['engines']['growing'] ?? null;

        $this->assertNotNull($growing);

        $history = TargetHistory::fromArray(['version' => 1, 'target' => 'growing', 'features' => 50]);

        $this->assertNotNull($history);

        (new TargetScheduler($engines['executor'], $engines['engines'], 1, null, static fn (): bool => $growing->statistics()->executions >= 16, null, ['growing' => $history]))->run();

        $this->assertFalse($growing->hasReplayedInputsToStartWith());
        $this->assertSame(50, $history->features());
    }

    public function testStopsFuzzingATargetThatFoundADefectWhileTheOtherTargetsGoOn(): void
    {
        $engines = $this->engines(
            [
                'failing' => static function (string $bytes): void
                {
                    throw new LogicException;
                },
                'passing' => static function (string $bytes): void
                {
                    Edges::through(1, null);
                },
            ],
            100,
            true,
        );

        $failing = $engines['engines']['failing'] ?? null;
        $passing = $engines['engines']['passing'] ?? null;

        $this->assertNotNull($failing);
        $this->assertNotNull($passing);

        (new TargetScheduler($engines['executor'], $engines['engines'], 1))->run();

        $this->assertSame(1, $failing->statistics()->findings);
        $this->assertLessThan(100, $failing->statistics()->executions);
        $this->assertSame(100, $passing->statistics()->executions);
        $this->assertSame([], (new TargetScheduler($engines['executor'], $engines['engines'], 1))->activeEngines());
    }

    public function testProcessesTheResultsOfTheInputsThatWereSubmittedBeforeFuzzingStopped(): void
    {
        $engines = $this->engines($this->twoTargets(), 4, false);

        (new TargetScheduler($this->executorThatReturnsOneResultAtATime($engines['executor']), $engines['engines'], 1))->run();

        foreach ($engines['engines'] as $engine) {
            $this->assertSame(4, $engine->statistics()->executions);
            $this->assertFalse($engine->hasPendingInputs());
        }
    }

    /**
     * Two targets that take one edge for every input.
     *
     * @return non-empty-array<non-empty-string, Closure(string): void>
     */
    private function twoTargets(): array
    {
        $target = static function (string $bytes): void
        {
            Edges::through(1, null);
        };

        return ['first' => $target, 'second' => $target];
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
     * Engines for bytes targets that share an executor, which executes each
     * input with the runner of the target that the input names.
     *
     * @param non-empty-array<non-empty-string, Closure(string): void> $targets
     * @param ?positive-int                                            $maximumNumberOfExecutions
     * @param array<non-empty-string, list<string>>                    $storedInputs              the bytes of the stored inputs of the targets
     *
     * @return array{executor: Executor, engines: non-empty-array<non-empty-string, Engine>}
     */
    private function engines(array $targets, ?int $maximumNumberOfExecutions, bool $stopOnFinding, array $storedInputs = []): array
    {
        $runners = [];

        foreach ($targets as $name => $target) {
            $runners[$name] = new Runner(Target::bytes('target', $target, 64), 8192, 0, false);
        }

        $executor = new class($runners) implements Executor
        {
            /**
             * @var list<Job>
             */
            private array $pending = [];

            /**
             * @param non-empty-array<non-empty-string, Runner> $runners
             */
            public function __construct(private readonly array $runners)
            {
            }

            public function target(): TargetDescription
            {
                return new TargetDescription('phpunit', null, 8192);
            }

            public function capacity(): int
            {
                return $this->pending === [] ? 1 : 0;
            }

            public function submit(array $jobs): void
            {
                foreach ($jobs as $job) {
                    $this->pending[] = $job;
                }
            }

            public function collect(): array
            {
                $results = [];

                while ($this->pending !== []) {
                    $results[] = $this->execute(array_shift($this->pending));
                }

                return $results;
            }

            public function execute(Job $job): Result
            {
                $runner = $this->runners[(string) $job->target()] ?? null;

                assert($runner !== null);

                return $runner->run($job);
            }

            public function shutdown(): void
            {
            }
        };

        $ids        = new JobIds;
        $dictionary = new Dictionary;
        $engines    = [];

        foreach ($targets as $name => $target) {
            $corpus = new Corpus;

            $engines[$name] = new Engine(
                $executor,
                $corpus,
                $dictionary,
                Mutator::create($corpus, $dictionary, 8192),
                // only the first finding is new
                new class implements FindingHandler
                {
                    private bool $found = false;

                    public function handle(Result $result, Job $job): bool
                    {
                        $new = !$this->found;

                        $this->found = true;

                        return $new;
                    }
                },
                new EngineConfiguration(1, $maximumNumberOfExecutions, null, $stopOnFinding, 16, $name),
                null,
                null,
                null,
                $ids,
            );

            $engines[$name]->start(
                array_map(
                    static fn (string $bytes): array => BytesEncoding::choicesFor($bytes, 64),
                    $storedInputs[$name] ?? [],
                ),
            );
        }

        return ['executor' => $executor, 'engines' => $engines];
    }
}
