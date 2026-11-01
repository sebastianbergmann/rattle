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

    /**
     * Engines for bytes targets that share an executor, which executes each
     * input with the runner of the target that the input names.
     *
     * @param non-empty-array<non-empty-string, Closure(string): void> $targets
     * @param ?positive-int                                            $maximumNumberOfExecutions
     *
     * @return array{executor: Executor, engines: non-empty-array<non-empty-string, Engine>}
     */
    private function engines(array $targets, ?int $maximumNumberOfExecutions, bool $stopOnFinding): array
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

            $engines[$name]->start([]);
        }

        return ['executor' => $executor, 'engines' => $engines];
    }
}
