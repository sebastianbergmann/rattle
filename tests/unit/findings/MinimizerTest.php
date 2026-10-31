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

use function count;
use function in_array;
use function str_contains;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(Minimizer::class)]
#[CoversClass(Minimization::class)]
#[UsesClass(NewFeatures::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Features::class)]
#[UsesClass(InProcessExecutor::class)]
#[UsesClass(Input::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Target::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(ValueExporter::class)]
#[Small]
final class MinimizerTest extends TestCase
{
    private static int $executions = 0;

    public function testMinimizesAnInputThatFailsInTheSameWay(): void
    {
        $original = new ChoiceSequence(BytesEncoding::choicesFor('hello, wonderful world', 100));
        $executor = $this->executor(static function (string $bytes): void
        {
            if (str_contains($bytes, 'w')) {
                throw new LogicException('w');
            }
        });

        $failure      = $executor->execute(Job::replay(0, $original->choices()))->failure();
        $minimization = (new Minimizer($executor))->minimize($original, $this->notNull($failure));

        $this->assertFalse($minimization->isFlaky());
        $this->assertTrue($minimization->minimalInputReproduced());
        $this->assertSame($original, $minimization->original());
        $this->assertSame([1, 0x77, 0], $minimization->minimal()->choices());
        $this->assertSame(["'w'"], $minimization->drawnValues());
        $this->assertSame('w', $minimization->failure()->message());
        $this->assertGreaterThan(0, $minimization->shrinking()?->numberOfSteps());
    }

    public function testDoesNotMinimizeAnInputThatDoesNotFailInTheSameWayAgain(): void
    {
        self::$executions = 0;

        $original = new ChoiceSequence(BytesEncoding::choicesFor('abc', 100));
        $executor = $this->executor(static function (string $bytes): void
        {
            if (self::$executions++ === 0) {
                throw new LogicException('only once');
            }
        });

        $failure      = $executor->execute(Job::replay(0, $original->choices()))->failure();
        $minimization = (new Minimizer($executor))->minimize($original, $this->notNull($failure));

        $this->assertTrue($minimization->isFlaky());
        $this->assertFalse($minimization->minimalInputReproduced());
        $this->assertSame($original, $minimization->minimal());
        $this->assertNull($minimization->shrinking());
        $this->assertSame(["'abc'"], $minimization->drawnValues());
    }

    public function testKeepsTheOriginalInputWhenTheMinimalInputDoesNotFailInTheSameWayAgain(): void
    {
        self::$executions = 0;

        $original = new ChoiceSequence(BytesEncoding::choicesFor('abc', 100));
        $executor = $this->executor(static function (string $bytes): void
        {
            // the input as it was found, its reproduction, and the first
            // attempt to shrink it fail, later executions do not
            if (self::$executions++ < 3) {
                throw new LogicException('at first');
            }
        });

        $failure      = $executor->execute(Job::replay(0, $original->choices()))->failure();
        $minimization = (new Minimizer($executor))->minimize($original, $this->notNull($failure));

        $this->assertFalse($minimization->isFlaky());
        $this->assertFalse($minimization->minimalInputReproduced());
        $this->assertSame($original, $minimization->minimal());
        $this->assertSame('at first', $minimization->failure()->message());
    }

    public function testMinimizesAnInputThatExceedsTheTimeLimitWithAtMostFiftyAttempts(): void
    {
        // inputs of 18 choices that are all positive exceed the time limit,
        // which keeps the shrinker busy with minimizing each choice
        $executor = new FakeExecutor(
            static function (Job $job): Result
            {
                $choices = $job->choices() ?? [];

                if (count($choices) >= 18 && !in_array(0, $choices, true)) {
                    return new Result($job->id(), Outcome::Failed, Failure::timeout('timeout', ''), $job->sequence(), [], null);
                }

                return new Result($job->id(), Outcome::Passed, null, $job->sequence(), [], null);
            },
        );

        $original     = new ChoiceSequence([9, 8, 7, 6, 5, 4, 3, 2, 1, 9, 8, 7, 6, 5, 4, 3, 2, 1]);
        $minimization = (new Minimizer($executor, 500))->minimize($original, Failure::timeout('timeout', ''));

        // four attempts at most to reproduce, fifty to shrink, one to replay
        $this->assertLessThanOrEqual(1 + 50 + 1, count($executor->executed));
        $this->assertTrue($minimization->shrinking()?->budgetExhausted());
    }

    public function testKeepsTheCandidateAsItWasSentWhenTheWorkerDies(): void
    {
        $executor = new FakeExecutor(
            static function (Job $job): Result
            {
                if (in_array(7, $job->choices() ?? [], true)) {
                    return Result::processEnded($job, Failure::processEnded(9, null));
                }

                return new Result($job->id(), Outcome::Passed, null, $job->sequence(), [], null);
            },
        );

        $original     = new ChoiceSequence([1, 2, 3, 7, 4, 5]);
        $minimization = (new Minimizer($executor))->minimize($original, Failure::processEnded(9, null));

        $this->assertSame([7], $minimization->minimal()->choices());
        $this->assertSame('Process terminated by signal 9', $minimization->failure()->describe());
    }

    public function testCanBeInterrupted(): void
    {
        $executor     = new FakeExecutor(static fn (Job $job): Result => Result::processEnded($job, Failure::processEnded(9, null)));
        $minimization = (new Minimizer($executor, 500, static fn (): bool => true))->minimize(new ChoiceSequence([1]), Failure::processEnded(9, null));

        $this->assertTrue($minimization->isFlaky());
    }

    public function testRunsAnInputOnceWithTheValuesItDraws(): void
    {
        $result = (new Minimizer($this->executor(static fn (string $bytes) => null)))->run(new ChoiceSequence([1, 0x61, 0]));

        $this->assertSame(Outcome::Passed, $result->outcome());
        $this->assertSame(["'a'"], $result->drawnValues());
    }

    /**
     * @param Closure(string): void $target
     */
    private function executor(Closure $target): InProcessExecutor
    {
        return new InProcessExecutor(new Runner(Target::bytes('target', $target, 100), 8192, 0, false));
    }

    private function notNull(?Failure $failure): Failure
    {
        $this->assertNotNull($failure);

        return $failure;
    }
}
