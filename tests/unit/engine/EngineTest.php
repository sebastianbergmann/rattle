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
use function array_slice;
use function count;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(Engine::class)]
#[CoversClass(EngineConfiguration::class)]
#[CoversClass(Statistics::class)]
#[UsesClass(NewFeatures::class)]
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
#[UsesClass(InsertToken::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Mutator::class)]
#[UsesClass(Dictionary::class)]
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

    public function testReplaysTheInitialInputsFirst(): void
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

        $engine->run([[1, 0x52, 0], [0]]);

        $this->assertSame([[1, 0x52, 0], [0]], array_map(static fn (Job $job): ?array => $job->choices(), array_slice($submitted, 0, 2)));
        $this->assertCount(20, $submitted);
        // the empty string produces no feature that "R" does not produce
        $this->assertGreaterThanOrEqual(1, $engine->statistics()->corpusEntries);
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

    public function testStopsWhenTheTimeIsUp(): void
    {
        $engine = $this->engine($this->magicHeader(), $this->findings(), new EngineConfiguration(1, null, 0.05));

        $this->assertGreaterThan(0, $engine->run()->executions);
    }

    /**
     * @return list<array{0: ?list<non-negative-int>, 1: ?int}>
     */
    private function submittedInputs(int $seed): array
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
    private function engine(Closure $target, FindingHandler $findings, EngineConfiguration $configuration, array $tokens = [], ?Closure $submitted = null, ?Closure $interrupted = null, ?Executor $executor = null): Engine
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
        );
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
