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
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(WorkerException::class)]
#[Large]
final class ProcessExecutorTest extends TestCase
{
    private const string FIXTURES      = __DIR__ . '/../../fixtures/worker';
    private ?ProcessExecutor $executor = null;

    protected function tearDown(): void
    {
        $this->executor?->shutdown();
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
                [1, 'failed', sprintf('Warning at %s:%d', $source, 63)],
                [2, 'failed', sprintf('Fatal error at %s:%d', $source, 68)],
                [3, 'passed', null],
                [4, 'failed', 'Process exited with status 3'],
                [5, 'passed', null],
                [6, 'failed', 'Process terminated by signal 9'],
                [7, 'passed', null],
                [8, 'failed', 'exceeding the time limit'],
                [9, 'passed', null],
                [10, 'failed', sprintf('%s at %s:%d', LogicException::class, $source, 86)],
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
                Job::replay(5, [Behaviour::PASS, 0]),
            ],
        );

        $source = str_replace(realpath((string) getcwd()) . '/', '', (string) realpath(self::FIXTURES . '/src/Behaviour.php'));

        $this->assertSame(
            [
                [0, 'failed', sprintf('%s at %s:%d', PathTraversal::class, $source, 116)],
                [1, 'failed', sprintf('%s at %s:%d', PathTraversal::class, $source, 116)],
                [2, 'failed', sprintf('%s at %s:%d', CodeInjection::class, $source, 126)],
                [3, 'failed', sprintf('%s at %s:%d', UnsafeDeserialization::class, $source, 129)],
                [4, 'failed', sprintf('%s at %s:%d', CodeInjection::class, $source, 134)],
                [5, 'passed', null],
            ],
            array_map(
                static fn (Result $result): array => [$result->id(), $result->outcome()->value, $result->failure()?->describe()],
                $this->collect($executor, 6),
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
        $this->assertSame(sprintf('%s at %s:%d', PathTraversal::class, $source, 18), $result->failure()?->describe());
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
