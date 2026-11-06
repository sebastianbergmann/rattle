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
use function count;
use function dirname;
use function getcwd;
use function ord;
use function realpath;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_split;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\Rattle\Fixture\Worker\Behaviour;

#[CoversClass(ProcessExecutor::class)]
#[CoversClass(WorkerProcess::class)]
#[CoversClass(Worker::class)]
#[CoversClass(WorkerCommand::class)]
#[CoversClass(WorkerConfiguration::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Frame::class)]
#[UsesClass(FrameBuffer::class)]
#[UsesClass(Job::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(PhpBinary::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(WorkerException::class)]
#[Large]
final class ProcessExecutorTest extends TestCase
{
    private const string FIXTURES             = __DIR__ . '/../../fixtures/worker';
    private ?ProcessExecutor $executor        = null;
    private ?ProcessExecutor $targetsExecutor = null;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workersThatMisbehaveWhileTheyStart(): array
    {
        return [
            'another version of the protocol' => ['other-protocol', 'The worker speaks another version of the protocol'],
            'invalid maximum of choices'      => ['invalid-handshake', 'The worker sent an invalid handshake'],
            'no name'                         => ['no-name', 'The worker sent an invalid handshake: Expected a non-empty string'],
            'invalid description of a target' => ['invalid-target-description', 'The worker sent an invalid handshake: Invalid description of a target'],
            'no targets'                      => ['no-targets', 'The worker sent an invalid handshake: it describes no target'],
            'invalid frame'                   => ['invalid-frame', 'The worker could not be started: Invalid frame: '],
            'process that ends'               => ['exits', "The worker process ended before it was ready\n\nthe worker cannot start"],
        ];
    }

    protected function tearDown(): void
    {
        $this->executor?->shutdown();
        $this->targetsExecutor?->shutdown();
    }

    public function testDescribesTheTargetThatTheWorkersLoaded(): void
    {
        $target = $this->executor()->start();

        $this->assertSame('worker', $target->name());
        $this->assertFalse($target->isBytesTarget());
        $this->assertSame(8192, $target->maximumNumberOfChoices());
        $this->assertSame($target, $this->executor()->target());
    }

    public function testAttributesEveryWayInWhichAWorkerDiesToTheInputThatKilledItAndExecutesTheRestOfTheBatch(): void
    {
        $executor = $this->executor();
        $executor->start();

        $behaviours = [
            Behaviour::PASS,
            Behaviour::WARNING,
            Behaviour::FATAL_ERROR,
            Behaviour::PASS,
            Behaviour::EXIT,
            Behaviour::PASS,
            Behaviour::KILL,
            Behaviour::PASS,
            Behaviour::ENDLESS_LOOP,
            Behaviour::PASS,
            Behaviour::EXCEPTION,
            Behaviour::REJECT,
            Behaviour::EXPECTED_EXCEPTION,
            Behaviour::OUTPUT,
        ];

        $jobs = [];

        foreach ($behaviours as $id => $behaviour) {
            $jobs[] = Job::replay($id, [$behaviour, 1]);
        }

        $executor->submit($jobs);

        $results = $this->collect($executor, count($jobs));
        // the workers run in the working directory of the test, and the
        // paths in locations are relative to it
        $source = str_replace(realpath((string) getcwd()) . '/', '', (string) realpath(self::FIXTURES . '/src/Behaviour.php'));

        $this->assertSame(
            [
                [0, 'passed', null],
                [1, 'failed', sprintf('Warning at %s:%d', $source, 65)],
                [2, 'failed', sprintf('Fatal error at %s:%d', $source, 70)],
                [3, 'passed', null],
                [4, 'failed', 'Process exited with status 3'],
                [5, 'passed', null],
                [6, 'failed', 'Process terminated by signal 9'],
                [7, 'passed', null],
                [8, 'failed', 'exceeding the time limit'],
                [9, 'passed', null],
                [10, 'failed', sprintf('%s at %s:%d', LogicException::class, $source, 88)],
                [11, 'rejected', null],
                [12, 'expected', null],
                [13, 'passed', null],
            ],
            array_map(
                static fn (Result $result): array => [$result->id(), $result->outcome()->value, $result->failure()?->describe()],
                $results,
            ),
        );

        // the sequence that a worker consumed is reported for a fatal error
        // and for exit(), but not for a worker that was killed
        $this->assertSame([Behaviour::FATAL_ERROR, 1], $this->resultAt($results, 2)->sequence()->choices());
        $this->assertCount(2, $this->resultAt($results, 2)->sequence()->spans());
        $this->assertCount(2, $this->resultAt($results, 4)->sequence()->spans());
        $this->assertSame([], $this->resultAt($results, 6)->sequence()->spans());

        $this->assertSame(3, $executor->numberOfRestarts());
    }

    public function testAttributesOutputThatCorruptsTheProtocolToTheInputThatProducedIt(): void
    {
        $executor = $this->executor();
        $executor->start();

        $executor->submit([Job::replay(1, [Behaviour::STDOUT, 1]), Job::replay(2, [Behaviour::PASS, 1])]);

        $results = $this->collect($executor, 2);

        $this->assertSame('Invalid output of the worker process', $this->resultAt($results, 0)->failure()?->describe());
        $this->assertSame(Outcome::Passed, $this->resultAt($results, 1)->outcome());
        $this->assertSame(1, $executor->numberOfRestarts());
    }

    public function testKillsAWorkerThatDoesNotReportAResultInTime(): void
    {
        $executor = $this->executor();
        $executor->start();

        $executor->submit([Job::replay(1, [Behaviour::UNINTERRUPTIBLE, 1]), Job::replay(2, [Behaviour::PASS, 1])]);

        $results = $this->collect($executor, 2);

        $this->assertTrue($this->resultAt($results, 0)->failure()?->isTimeout());
        $this->assertStringContainsString('did not report a result within 3 seconds', $this->resultAt($results, 0)->failure()->message());
        $this->assertSame(Outcome::Passed, $this->resultAt($results, 1)->outcome());
        $this->assertSame(1, $executor->numberOfRestarts());
    }

    public function testReplacesAWorkerAfterTheConfiguredNumberOfExecutions(): void
    {
        $executor = $this->executor(1, 2);
        $executor->start();

        for ($batch = 0; $batch < 3; $batch++) {
            // the worker that retires is replaced while results are collected
            if ($executor->capacity() === 0) {
                $this->assertSame(0, $this->collectAll($executor));
            }

            $this->assertSame(1, $executor->capacity());

            $executor->submit([Job::replay(2 * $batch, [Behaviour::PASS, 0]), Job::replay(2 * $batch + 1, [Behaviour::PASS, 1])]);

            $results = $this->collect($executor, 2);

            $this->assertSame(Outcome::Passed, $this->resultAt($results, 0)->outcome());
            $this->assertSame(Outcome::Passed, $this->resultAt($results, 1)->outcome());

            // a new worker reports the features that it has not seen before
            $this->assertNotSame([], $this->resultAt($results, 0)->features());
        }

        // the worker retires after each batch
        $this->assertSame(0, $this->collectAll($executor));
        $this->assertSame(3, $executor->numberOfRestarts());
    }

    public function testReportsAnInputThatControlsADangerousSinkAtTheFrameInTheSourceDirectoriesEvenWhenTheTargetCatchesTheVulnerability(): void
    {
        $executor = $this->executor();
        $executor->start();

        $executor->submit(
            [
                Job::replay(0, [Behaviour::TRAVERSAL, 0]),
                Job::replay(1, [Behaviour::TRAVERSAL, 1]),
                Job::replay(2, [Behaviour::INCLUSION, 0]),
                Job::replay(3, [Behaviour::DESERIALIZATION, 0]),
                Job::replay(4, [Behaviour::EVALUATION, 0]),
                Job::replay(5, [Behaviour::COMMAND, 0]),
                Job::replay(6, [Behaviour::PASS, 0]),
            ],
        );

        $source = str_replace(realpath((string) getcwd()) . '/', '', (string) realpath(self::FIXTURES . '/src/Behaviour.php'));

        $this->assertSame(
            [
                [0, 'failed', sprintf('%s at %s:%d', PathTraversal::class, $source, 118)],
                [1, 'failed', sprintf('%s at %s:%d', PathTraversal::class, $source, 118)],
                [2, 'failed', sprintf('%s at %s:%d', CodeInjection::class, $source, 128)],
                [3, 'failed', sprintf('%s at %s:%d', UnsafeDeserialization::class, $source, 131)],
                [4, 'failed', sprintf('%s at %s:%d', CodeInjection::class, $source, 136)],
                [5, 'failed', sprintf('%s at %s:%d', CommandInjection::class, $source, 141)],
                [6, 'passed', null],
            ],
            array_map(
                static fn (Result $result): array => [$result->id(), $result->outcome()->value, $result->failure()?->describe()],
                $this->collect($executor, 7),
            ),
        );
    }

    public function testReportsTheConstantOperandsOfComparisonsInTheCodeThatIsInstrumentedOnce(): void
    {
        $executor = $this->executor();
        $executor->start();

        $first  = $executor->execute(Job::replay(0, [Behaviour::PASS, 0]));
        $second = $executor->execute(Job::replay(1, [Behaviour::PASS, 0]));

        // Behaviour::passed() compares with 'pass'; the switch of
        // Behaviour::run() compares with integers that are too short
        $this->assertSame(['pass'], $first->tokens());
        $this->assertSame([], $second->tokens());
    }

    public function testServesAllThePropertiesOfAPhpunitTestSuite(): void
    {
        $executor = $this->testSuiteExecutor();
        $classify = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList';
        $counts   = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testCountsAreNotNegative';

        $this->assertSame([$classify, $counts], $executor->properties());
        $this->assertSame(8192, $executor->target()->maximumNumberOfChoices());

        $short  = $executor->execute(Job::replay(0, [0])->withTarget($classify));
        $failed = $executor->execute(Job::replay(1, [1, 7, 1, 3, 1, 9, 0])->withTarget($classify));
        $passed = $executor->execute(Job::replay(2, [1, 7, 1, 3, 1, 9, 0])->withTarget($counts));

        $this->assertSame(Outcome::Passed, $short->outcome());
        $this->assertNotSame([], $short->features());
        $this->assertSame(Outcome::Failed, $failed->outcome());
        $this->assertSame(Outcome::Passed, $passed->outcome());
    }

    public function testReportsAnExampleThatControlsADangerousSinkAtTheFrameInTheSourceDirectories(): void
    {
        $executor   = $this->testSuiteExecutor('detectors.xml');
        $properties = $executor->properties() ?? [];
        $choices    = [];

        $this->assertCount(1, $properties);

        // the characters of a string, each preceded by the choice that
        // there is another one
        foreach (str_split(str_repeat('../', 16) . 'rattle-sentinel') as $character) {
            $choices[] = 1;
            $choices[] = CodePoints::index(ord($character));
        }

        $choices[] = 0;

        $result = $executor->execute(Job::replay(0, $choices)->withTarget($properties[0] ?? 'missing'));
        $source = str_replace(realpath((string) getcwd()) . '/', '', (string) realpath(dirname(__DIR__, 2) . '/fixtures/phpunit/src/Templates.php'));

        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertSame(sprintf('%s at %s:%d', PathTraversal::class, $source, 23), $result->failure()?->describe());
        $this->assertSame($choices, $result->sequence()->choices());
    }

    public function testAWorkerThatServesAllThePropertiesOfATestSuiteRejectsAnInputThatNamesNoProperty(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains('An input does not name a property of the test suite');

        $this->testSuiteExecutor()->execute(Job::replay(0, [0]));
    }

    public function testExecutesBatchesOnSeveralWorkers(): void
    {
        $executor = $this->executor(3);
        $executor->start();

        $this->assertSame(3, $executor->capacity());

        for ($batch = 0; $batch < 3; $batch++) {
            $executor->submit([Job::replay($batch, [Behaviour::PASS, 0]), Job::generate(10 + $batch, $batch)]);
        }

        $this->assertSame(0, $executor->capacity());
        $this->assertCount(6, $this->collect($executor, 6));
        $this->assertSame(3, $executor->capacity());
    }

    public function testStartsTheWorkersWithAnotherPhpBinary(): void
    {
        $php = PhpBinary::at(dirname(__DIR__, 2) . '/fixtures/php/php-precision-5');

        $this->executor = new ProcessExecutor($this->configuration(), 1, null, $php);

        $this->executor->start();

        $this->assertSame($php, $this->executor->php());
        $this->assertSame(Outcome::Passed, $this->executor->execute(Job::replay(0, [Behaviour::PASS, 0]))->outcome());
        $this->assertSame(PhpBinary::current()->path(), (new ProcessExecutor($this->configuration(), 1))->php()->path());
    }

    public function testExecutesOneInputAtATime(): void
    {
        $executor = $this->executor();

        $result = $executor->execute(Job::replay(5, [Behaviour::EXIT, 0], true));

        $this->assertSame('Process exited with status 3', $result->failure()?->describe());
        $this->assertSame(['4', 'false'], $result->drawnValues());
        $this->assertSame(Outcome::Passed, $executor->execute(Job::replay(6, [Behaviour::PASS, 0]))->outcome());
    }

    public function testForwardsWhatWorkersWriteToTheirStandardErrorStream(): void
    {
        $stderr   = '';
        $executor = new ProcessExecutor(
            $this->configuration(),
            1,
            static function (int $worker, string $bytes) use (&$stderr): void
            {
                $stderr .= $bytes;
            },
        );

        $this->executor = $executor;

        $executor->execute(Job::replay(1, [Behaviour::OUTPUT, 0]));
        $executor->shutdown();

        $this->assertStringContainsString('output that must not interfere with the protocol', $stderr);
    }

    public function testCannotStartAWorkerForAFileThatDoesNotReturnATarget(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains('must return an instance of SebastianBergmann\Rattle\Target, string returned');

        (new ProcessExecutor(new WorkerConfiguration(self::FIXTURES . '/not-a-target.php', null, [], 1, 100, null, false, 100), 1))->start();
    }

    public function testCannotStartAWorkerWithABootstrapFileThatDoesNotExist(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains('The bootstrap file "/does/not/exist.php" does not exist');

        (new ProcessExecutor(new WorkerConfiguration(self::FIXTURES . '/target.php', '/does/not/exist.php', [], 1, 100, null, false, 100), 1))->start();
    }

    public function testDescribesTheTargetsOfSeveralTargetFiles(): void
    {
        $executor = $this->targetsExecutor();
        $targets  = $executor->targets();

        $this->assertNotNull($targets);
        $this->assertSame(['worker', 'bytes'], array_keys($targets));
        $this->assertFalse($this->targetNamed($targets, 'worker')->isBytesTarget());
        $this->assertSame(16, $this->targetNamed($targets, 'bytes')->maximumLength());
        $this->assertSame(8192, $this->targetNamed($targets, 'bytes')->maximumNumberOfChoices());
        $this->assertSame(['worker' => self::FIXTURES . '/target.php', 'bytes' => self::FIXTURES . '/bytes-target.php'], $executor->targetFiles());
        $this->assertSame('targets', $executor->target()->name());
        $this->assertNull($this->executor()->targets());
        $this->assertSame([], $this->executor()->targetFiles());
    }

    public function testExecutesTheInputsOfEveryTargetOfSeveralTargetFilesAndKeepsTheirFeaturesApart(): void
    {
        $executor = $this->targetsExecutor();

        $first  = $executor->execute(Job::replay(0, [Behaviour::PASS, 0])->withTarget('worker'));
        $again  = $executor->execute(Job::replay(1, [Behaviour::PASS, 0])->withTarget('worker'));
        $bytes  = $executor->execute(Job::replay(2, [0])->withTarget('bytes'));
        $failed = $executor->execute(Job::replay(3, [1, 112, 1, 97, 1, 115, 1, 115, 0])->withTarget('bytes'));

        $this->assertSame(Outcome::Passed, $first->outcome());
        $this->assertNotSame([], $first->features());
        $this->assertSame([], $again->features());
        $this->assertSame(Outcome::Passed, $bytes->outcome());
        $this->assertNotSame([], $bytes->features());
        $this->assertSame(Outcome::Failed, $failed->outcome());
        $this->assertStringStartsWith('LogicException', $failed->failure()?->describe() ?? '');
    }

    public function testReportsTheConstantOperandsOfComparisonsThatWereFoundWhileAWorkerWithSeveralTargetsStartedWithTheFirstResultOfEveryTarget(): void
    {
        $executor = $this->targetsExecutor();

        $first  = $executor->execute(Job::replay(0, [Behaviour::PASS, 0])->withTarget('worker'));
        $second = $executor->execute(Job::replay(1, [Behaviour::PASS, 0])->withTarget('worker'));
        $bytes  = $executor->execute(Job::replay(2, [0])->withTarget('bytes'));

        // bytes-target.php creates a Behaviour, whose code is instrumented
        // while the worker starts
        $this->assertSame(['pass'], $first->tokens());
        $this->assertSame([], $second->tokens());
        $this->assertSame(['pass'], $bytes->tokens());
    }

    public function testAWorkerThatServesSeveralTargetsRejectsAnInputThatNamesNoneOfThem(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains('An input does not name one of the targets of the worker');

        $this->targetsExecutor()->execute(Job::replay(0, [0])->withTarget('other'));
    }

    public function testCannotStartAWorkerForTwoTargetFilesWhoseTargetsHaveTheSameName(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains(sprintf('The target files "%s" and "%s" both return a target named "worker"', self::FIXTURES . '/target.php', self::FIXTURES . '/duplicate-target.php'));

        $this->targetsExecutor([self::FIXTURES . '/target.php', self::FIXTURES . '/duplicate-target.php'])->start();
    }

    #[DataProvider('workersThatMisbehaveWhileTheyStart')]
    public function testCannotStartAWorkerThatMisbehavesWhileItStarts(string $behaviour, string $message): void
    {
        $executor = $this->misbehavingExecutor($behaviour);

        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $executor->start();
    }

    public function testRejectsAMessageOfAWorkerThatIsNotAResult(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageIsOrContains('Unexpected message from a worker');

        $this->misbehavingExecutor('unexpected-message')->execute(Job::replay(1, []));
    }

    public function testRejectsAResultOfAWorkerForAnInputThatItWasNotExecuting(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageIsOrContains('A worker reported a result for an input that it was not executing');

        $this->misbehavingExecutor('unknown-result')->execute(Job::replay(1, []));
    }

    /**
     * An executor whose worker misbehaves in the given way (see the fixture
     * php-misbehaving-worker).
     */
    private function misbehavingExecutor(string $behaviour): ProcessExecutor
    {
        $this->executor = new ProcessExecutor(
            new WorkerConfiguration($behaviour, null, [], 1, 100, null, false, 100),
            1,
            null,
            PhpBinary::at(dirname(__DIR__, 2) . '/fixtures/php/php-misbehaving-worker'),
        );

        return $this->executor;
    }

    /**
     * An executor whose workers load several target files of the fixture.
     *
     * @param ?non-empty-list<string> $files
     */
    private function targetsExecutor(?array $files = null): ProcessExecutor
    {
        $this->targetsExecutor = new ProcessExecutor($this->configuration()->withTargets($files ?? [self::FIXTURES . '/target.php', self::FIXTURES . '/bytes-target.php']), 1);

        return $this->targetsExecutor;
    }

    /**
     * @param array<non-empty-string, TargetDescription> $targets
     */
    private function targetNamed(array $targets, string $name): TargetDescription
    {
        $target = $targets[$name] ?? null;

        $this->assertNotNull($target);

        return $target;
    }

    /**
     * An executor whose workers serve all the properties of the PHPUnit test
     * suite of the fixture.
     */
    private function testSuiteExecutor(string $configurationFile = 'phpunit.xml'): ProcessExecutor
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/phpunit';

        $this->executor = new ProcessExecutor(
            new WorkerConfiguration(
                '',
                dirname(__DIR__, 3) . '/vendor/autoload.php',
                [$fixture . '/src'],
                1,
                8192,
                '64M',
                false,
                10000,
                new PhpunitTestSuite($fixture . '/' . $configurationFile, null),
            ),
            1,
        );

        return $this->executor;
    }

    /**
     * @param positive-int $numberOfWorkers
     * @param positive-int $numberOfExecutions
     */
    private function executor(int $numberOfWorkers = 1, int $numberOfExecutions = 10000): ProcessExecutor
    {
        if ($this->executor === null) {
            $this->executor = new ProcessExecutor($this->configuration($numberOfExecutions), $numberOfWorkers);
        }

        return $this->executor;
    }

    /**
     * @param positive-int $numberOfExecutions
     */
    private function configuration(int $numberOfExecutions = 10000): WorkerConfiguration
    {
        return new WorkerConfiguration(
            self::FIXTURES . '/target.php',
            self::FIXTURES . '/bootstrap.php',
            [self::FIXTURES . '/src'],
            1,
            8192,
            '64M',
            false,
            $numberOfExecutions,
        );
    }

    /**
     * @return list<Result>
     */
    private function collect(Executor $executor, int $count): array
    {
        $results = [];

        while (count($results) < $count) {
            foreach ($executor->collect() as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param list<Result> $results
     */
    private function resultAt(array $results, int $index): Result
    {
        $result = $results[$index] ?? null;

        $this->assertNotNull($result);

        return $result;
    }

    private function collectAll(Executor $executor): int
    {
        return count($executor->collect());
    }
}
