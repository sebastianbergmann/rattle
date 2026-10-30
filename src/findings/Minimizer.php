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

use function min;
use Closure;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\FailureSignature;
use SebastianBergmann\ChoiceSequence\Shrinker;

/**
 * Reproduces an input that made the target fail and minimizes it, as PHPUnit
 * does with a counterexample of a property.
 *
 * The input is replayed up to four times. When it never fails in the same way
 * again, it is flaky and is not minimized. Otherwise, it is minimized with the
 * Shrinker of sebastian/choice-sequence, whose test replays a candidate in a
 * dedicated executor and keeps the sequence that the replay consumed when the
 * candidate fails in the same way. A worker that dies reports no spans, so
 * the candidate as it was sent is kept then. Finally, the minimal input is
 * replayed once more. When it no longer fails in the same way, the input as
 * it was found is kept.
 *
 * Every attempt of an input that exceeds the time limit takes the full time
 * limit, so such an input is minimized with at most 50 attempts.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Minimizer
{
    public const int DEFAULT_MAXIMUM_NUMBER_OF_ATTEMPTS        = 500;
    private const int NUMBER_OF_REPRODUCTIONS                  = 4;
    private const int MAXIMUM_NUMBER_OF_ATTEMPTS_AFTER_TIMEOUT = 50;
    private readonly Executor $executor;

    /**
     * @var non-negative-int
     */
    private readonly int $maximumNumberOfAttempts;

    /**
     * @var ?Closure(): bool
     */
    private readonly ?Closure $shouldStop;

    /**
     * @var non-negative-int
     */
    private int $nextId = 0;

    /**
     * @param non-negative-int $maximumNumberOfAttempts
     * @param ?Closure(): bool $shouldStop              whether minimizing is to be interrupted, by Ctrl+C for instance
     */
    public function __construct(Executor $executor, int $maximumNumberOfAttempts = self::DEFAULT_MAXIMUM_NUMBER_OF_ATTEMPTS, ?Closure $shouldStop = null)
    {
        $this->executor                = $executor;
        $this->maximumNumberOfAttempts = $maximumNumberOfAttempts;
        $this->shouldStop              = $shouldStop;
    }

    public function minimize(ChoiceSequence $original, Failure $failure): Minimization
    {
        $signature = $failure->signature();

        if (!$this->reproduces($original, $signature)) {
            return new Minimization($original, $original, $failure, $this->drawnValuesOf($original), false, null, false);
        }

        $maximumNumberOfAttempts = $this->maximumNumberOfAttempts;

        if ($failure->isTimeout()) {
            $maximumNumberOfAttempts = min($maximumNumberOfAttempts, self::MAXIMUM_NUMBER_OF_ATTEMPTS_AFTER_TIMEOUT);
        }

        $shrinker = new Shrinker(
            function (ChoiceSequence $candidate) use ($signature): ?ChoiceSequence
            {
                $result = $this->replay($candidate, false);

                if (!$result->isFinding() || !$result->failure()->signature()->isSameAs($signature)) {
                    return null;
                }

                return $result->sequence();
            },
            $maximumNumberOfAttempts,
            $this->shouldStop,
        );

        $shrinking = $shrinker->shrink($original);
        $minimal   = $this->replay($shrinking->sequence(), true);

        if ($minimal->isFinding() && $minimal->failure()->signature()->isSameAs($signature)) {
            return new Minimization($original, $shrinking->sequence(), $minimal->failure(), $minimal->drawnValues() ?? [], true, $shrinking, true);
        }

        return new Minimization($original, $original, $failure, $this->drawnValuesOf($original), true, $shrinking, false);
    }

    /**
     * Executes the input once and returns its result, with the values it
     * draws.
     */
    public function run(ChoiceSequence $input): Result
    {
        return $this->replay($input, true);
    }

    private function reproduces(ChoiceSequence $input, FailureSignature $signature): bool
    {
        for ($i = 0; $i < self::NUMBER_OF_REPRODUCTIONS; $i++) {
            if ($this->shouldStop !== null && ($this->shouldStop)()) {
                return false;
            }

            $result = $this->replay($input, false);

            if ($result->isFinding() && $result->failure()->signature()->isSameAs($signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function drawnValuesOf(ChoiceSequence $input): array
    {
        return $this->replay($input, true)->drawnValues() ?? [];
    }

    private function replay(ChoiceSequence $input, bool $reportDrawnValues): Result
    {
        return $this->executor->execute(Job::replay($this->nextId++, $input->choices(), $reportDrawnValues));
    }
}
