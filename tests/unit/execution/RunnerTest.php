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

use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use function array_values;
use function dirname;
use function hrtime;
use function sprintf;
use function trigger_error;
use function usleep;
use Closure;
use Error;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\ChoiceSequence\FailureSignature;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;
use Throwable;

#[CoversClass(Runner::class)]
#[CoversClass(Outcome::class)]
#[CoversClass(TimeoutError::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Result::class)]
#[UsesClass(Input::class)]
#[UsesClass(InputRejectedException::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(Target::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(ValueExporter::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Features::class)]
#[UsesClass(PathTraversal::class)]
#[UsesClass(Vulnerability::class)]
#[Medium]
final class RunnerTest extends TestCase
{
    private const string FILE = 'tests/unit/execution/RunnerTest.php';

    protected function tearDown(): void
    {
        Edges::reset();
    }

    public function testAnInputPassesWhenTheTargetReturns(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            $input->bool();
        })->run(Job::replay(7, [1]));

        $this->assertSame(7, $result->id());
        $this->assertSame(Outcome::Passed, $result->outcome());
        $this->assertFalse($result->isFinding());
        $this->assertSame([1], $result->sequence()->choices());
        $this->assertSame(1, $result->sequence()->maximumAt(0));
        $this->assertCount(1, $result->sequence()->spans());
        $this->assertNull($result->drawnValues());
    }

    public function testTheResultOfATerseInputHasItsSequenceOnlyWhenItIsNeeded(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            Edges::through(1, null);

            if ($input->bool()) {
                throw new LogicException;
            }
        });

        $new      = $runner->run(Job::replay(1, [0])->terse());
        $seen     = $runner->run(Job::replay(2, [0])->terse());
        $failed   = $runner->run(Job::replay(3, [1])->terse());
        $required = $runner->run(Job::replay(4, [0])->withRequiredFeatures($new->features())->terse());

        $this->assertTrue($new->hasSequence());
        $this->assertFalse($seen->hasSequence());
        $this->assertTrue($failed->hasSequence());
        $this->assertTrue($required->hasSequence());
        $this->assertTrue($runner->run(Job::replay(5, [0]))->hasSequence());
    }

    public function testGeneratesInputsFromASeed(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            $input->bytes(100);
        });

        $first  = $runner->run(Job::generate(1, 42));
        $second = $runner->run(Job::generate(2, 42));
        $third  = $runner->run(Job::generate(3, 43));

        $this->assertSame($first->sequence()->choices(), $second->sequence()->choices());
        $this->assertNotSame($first->sequence()->choices(), $third->sequence()->choices());
    }

    public function testReportsTheDrawnValuesWhenAskedTo(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            $input->int(0, 10);
            $input->bytes(10);
        });

        $this->assertSame(['3', "'a'"], $runner->run(Job::replay(1, [3, 1, 0x61, 0], true))->drawnValues());
        $this->assertNull($runner->run(Job::replay(1, [3, 1, 0x61, 0]))->drawnValues());
    }

    public function testReportsFeaturesThatTheRunnerHasNotSeenBefore(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            Edges::through(1, null);

            if ($input->bool()) {
                Edges::through(2, null);
            }
        });

        $false = $runner->run(Job::replay(1, [0]));
        $true  = $runner->run(Job::replay(2, [1]));
        $again = $runner->run(Job::replay(3, [1]));

        $this->assertSame([1], $false->features());
        $this->assertSame([1 << 28 | 2], $true->features());
        $this->assertSame([], $again->features());
    }

    public function testTellsWhetherTheInputProducedTheFeaturesThatItRequired(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            Edges::through(1, null);

            if ($input->bool()) {
                Edges::through(2, null);
            }
        });

        $this->assertTrue($runner->run(Job::replay(1, [1])->withRequiredFeatures([1, 1 << 28 | 2]))->producedRequiredFeatures());
        $this->assertFalse($runner->run(Job::replay(2, [0])->withRequiredFeatures([1, 1 << 28 | 2]))->producedRequiredFeatures());
        $this->assertFalse($runner->run(Job::replay(3, [1]))->producedRequiredFeatures());
    }

    public function testReportsAllFeaturesWhenTheInputAsksForThem(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            Edges::through(1, null);

            if ($input->bool()) {
                Edges::through(2, null);
            }
        });

        $first = $runner->run(Job::replay(1, [1]));
        $all   = $runner->run(Job::replay(2, [1])->withAllFeatures());
        $again = $runner->run(Job::replay(3, [0]));

        $this->assertSame([1, 1 << 28 | 2], $first->features());
        $this->assertSame([1, 1 << 28 | 2], $all->features());
        $this->assertSame([], $again->features());
    }

    public function testAnInputThatEndsWithAnExpectedThrowableIsNotAFinding(): void
    {
        $runner = $this->runner(
            static function (Input $input): void
            {
                Edges::through(1, null);

                throw new RuntimeException('documented');
            },
            RuntimeException::class,
        );

        $result = $runner->run(Job::replay(1, []));

        $this->assertSame(Outcome::Expected, $result->outcome());
        $this->assertFalse($result->isFinding());
        $this->assertSame([1], $result->features());
    }

    public function testAnInputThatEndsWithAnotherThrowableIsAFinding(): void
    {
        $line   = __LINE__ + 6;
        $runner = $this->runner(
            static function (Input $input): void
            {
                Edges::through(1, null);

                throw new LogicException('undocumented');
            },
            RuntimeException::class,
        );

        $result = $runner->run(Job::replay(1, []));

        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertTrue($result->isFinding());
        $this->assertSame([], $result->features());
        $this->assertSame(Failure::THROWABLE, $result->failure()->kind());
        $this->assertSame('undocumented', $result->failure()->message());
        $this->assertStringStartsWith(self::FILE . ':' . $line, $result->failure()->trace());
        $this->assertTrue(
            FailureSignature::forThrowable(LogicException::class, self::FILE . ':' . $line)->isSameAs($result->failure()->signature()),
        );
    }

    public function testTheLocationOfAThrowableThatIsThrownByRattleIsTheFirstFrameOutsideOfIt(): void
    {
        $line   = __LINE__ + 3;
        $result = $this->runner(static function (Input $input): void
        {
            $input->pick([]);
        })->run(Job::replay(1, []));

        $this->assertSame(
            sprintf('%s at %s:%d', InvalidArgumentException::class, self::FILE, $line),
            $result->failure()?->describe(),
        );
    }

    public function testRejectedInputsAreNotFindings(): void
    {
        $runner = $this->runner(static function (Input $input): void
        {
            Edges::through(1, null);

            if ($input->bool()) {
                $input->reject();
            }

            try {
                $input->reject();
            } catch (InputRejectedException) {
                // the target swallows the rejection
            }
        });

        $this->assertSame(Outcome::Rejected, $runner->run(Job::replay(1, [1]))->outcome());

        $result = $runner->run(Job::replay(2, [0]));

        $this->assertSame(Outcome::Rejected, $result->outcome());
        $this->assertSame([], $result->features());
    }

    public function testAnInputThatNeedsTooManyChoicesIsRejected(): void
    {
        $runner = new Runner(
            Target::structured('target', static function (Input $input): void
            {
                for ($i = 0; $i < 10; $i++) {
                    $input->bool();
                }
            }),
            5,
            0,
            false,
        );

        $this->assertSame(Outcome::Rejected, $runner->run(Job::replay(1, []))->outcome());
        $this->assertSame(5, $runner->maximumNumberOfChoices());
    }

    public function testRaisesTheMaximumNumberOfChoicesToWhatABytesTargetNeeds(): void
    {
        $runner = new Runner(Target::bytes('target', static fn (string $bytes) => null, 100), 10, 0, false);

        $this->assertSame(201, $runner->maximumNumberOfChoices());
        $this->assertSame(TargetDescription::of($runner)->maximumNumberOfChoices(), 201);
    }

    public function testAWarningIsAFinding(): void
    {
        $line   = __LINE__ + 3;
        $result = $this->runner(static function (Input $input): void
        {
            trigger_error('something is wrong', E_USER_WARNING);
        })->run(Job::replay(1, []));

        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertSame(Failure::DESCRIPTION, $result->failure()?->kind());
        $this->assertSame(sprintf('Warning at %s:%d', self::FILE, $line), $result->failure()->describe());
        $this->assertSame('something is wrong', $result->failure()->message());
    }

    public function testANoticeIsAFinding(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            trigger_error('note this', E_USER_NOTICE);
        })->run(Job::replay(1, []));

        $this->assertStringStartsWith('Notice at ', $result->failure()?->describe() ?? '');
    }

    public function testADeprecationIsAFindingUnlessDeprecationsAreIgnored(): void
    {
        $target = static function (Input $input): void
        {
            trigger_error('deprecated', E_USER_DEPRECATED);
        };

        $this->assertStringStartsWith('Deprecated at ', $this->runner($target)->run(Job::replay(1, []))->failure()?->describe() ?? '');
        $this->assertSame(Outcome::Passed, new Runner(Target::structured('target', $target), 100, 0, true)->run(Job::replay(1, []))->outcome());
    }

    public function testASuppressedIssueIsNotAFinding(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            @trigger_error('suppressed', E_USER_WARNING);
        })->run(Job::replay(1, []));

        $this->assertSame(Outcome::Passed, $result->outcome());
    }

    public function testTheFirstFailureIsReported(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            trigger_error('first', E_USER_WARNING);
            trigger_error('second', E_USER_NOTICE);

            throw new LogicException('third');
        })->run(Job::replay(1, []));

        $this->assertSame('first', $result->failure()?->message());
    }

    public function testAnInputThatControlsADangerousSinkIsAFindingEvenWhenTheTargetCatchesOrExpectsTheVulnerability(): void
    {
        $line   = __LINE__ + 5;
        $runner = $this->runner(
            static function (Input $input): void
            {
                try {
                    throw Detectors::detect(PathTraversal::class, 'a detection');
                } catch (Throwable) {
                    // the target swallows the vulnerability
                }

                throw new Error('an expected error');
            },
            Error::class,
        );

        $result = $runner->run(Job::replay(1, []));

        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertSame(sprintf('%s at %s:%d', PathTraversal::class, self::FILE, $line), $result->failure()?->describe());
        $this->assertSame('a detection', $result->failure()->message());
    }

    public function testAnIssueThatHappensBeforeADetectionIsReported(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            trigger_error('first', E_USER_WARNING);

            throw Detectors::detect(PathTraversal::class, 'second');
        })->run(Job::replay(1, []));

        $this->assertSame('first', $result->failure()?->message());
    }

    public function testADetectionThatHappensBeforeAnIssueIsReported(): void
    {
        $result = $this->runner(static function (Input $input): void
        {
            try {
                throw Detectors::detect(PathTraversal::class, 'first');
            } catch (Throwable) {
                // the target swallows the vulnerability
            }

            trigger_error('second', E_USER_WARNING);
        })->run(Job::replay(1, []));

        $this->assertSame('first', $result->failure()?->message());
    }

    public function testAnInputThatExceedsTheTimeLimitIsAFindingEvenWhenTheTargetCatchesTheTimeoutError(): void
    {
        $runner = new Runner(
            Target::structured('target', static function (Input $input): void
            {
                try {
                    do {
                        usleep(1000);
                    } while (hrtime(true) > 0);
                } catch (Throwable) {
                    // the target swallows the timeout
                }
            }),
            100,
            1,
            false,
        );

        $result = $runner->run(Job::replay(1, []));

        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertTrue($result->failure()?->isTimeout());
        $this->assertTrue(FailureSignature::forTimeout()->isSameAs($result->failure()->signature()));
        $this->assertSame('Execution exceeded the time limit of 1 second', $result->failure()->message());
        $this->assertSame(Outcome::Passed, $this->runner(static fn (Input $input) => null)->run(Job::replay(2, []))->outcome());
    }

    public function testReportsTheInputThatWasExecutedWhenTheProcessEnded(): void
    {
        $interrupted = null;
        $runner      = null;

        $runner = $this->runner(static function (Input $input) use (&$runner, &$interrupted): void
        {
            $input->bool();

            /** @var Runner $runner */
            $interrupted = $runner->resultOfInterruptedExecution();
        });

        $this->assertNull($runner->resultOfInterruptedExecution());

        $runner->run(Job::replay(5, [1], true));

        $this->assertInstanceOf(Result::class, $interrupted);
        $this->assertSame(5, $interrupted->id());
        $this->assertTrue($interrupted->failure()?->isExit());
        $this->assertSame([1], $interrupted->sequence()->choices());
        $this->assertSame(['true'], $interrupted->drawnValues());
        $this->assertNull($runner->resultOfInterruptedExecution());
        $this->assertSame(1, $runner->numberOfExecutions());
    }

    public function testReportsTheDetectionOfTheInputThatWasExecutedWhenTheProcessEnded(): void
    {
        $interrupted = null;
        $runner      = null;

        $runner = $this->runner(static function (Input $input) use (&$runner, &$interrupted): void
        {
            try {
                throw Detectors::detect(PathTraversal::class, 'a detection');
            } catch (Throwable) {
                // the target swallows the vulnerability, and then exits
            }

            /** @var Runner $runner */
            $interrupted = $runner->resultOfInterruptedExecution();
        });

        $runner->run(Job::replay(1, []));

        $this->assertInstanceOf(Result::class, $interrupted);
        $this->assertSame('a detection', $interrupted->failure()?->message());
    }

    /**
     * @param Closure(Input): void    $test
     * @param class-string<Throwable> ...$expected
     */
    private function runner(Closure $test, string ...$expected): Runner
    {
        return new Runner(Target::structured('target', $test)->expect(...array_values($expected)), 100, 0, false, dirname(__DIR__, 3));
    }
}
