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

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const E_ALL;
use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_ERROR;
use const E_NOTICE;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;
use const SIGALRM;
use function debug_backtrace;
use function error_get_last;
use function error_reporting;
use function getcwd;
use function in_array;
use function max;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\TooManyChoicesException;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use Throwable;

/**
 * Executes inputs of a target, one at a time, and classifies their outcomes.
 *
 * An input fails when the target throws a throwable that is not expected,
 * when it triggers a warning, notice, deprecation, or user error that is not
 * suppressed, or when its execution exceeds the time limit. The first of
 * these that happens is how the input fails: a warning that is followed by an
 * exception, for instance, is reported as the warning.
 *
 * The features of an input that passes, or that ends with an expected
 * throwable, are reported when this runner has not seen them before. The
 * features of a rejected input and of an input that fails are not reported,
 * and are not remembered as seen.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Runner implements InputRunner
{
    private const array FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    private readonly Target $target;

    /**
     * @var positive-int
     */
    private readonly int $maximumNumberOfChoices;

    /**
     * @var non-negative-int
     */
    private readonly int $timeout;
    private readonly bool $ignoreDeprecations;
    private readonly Location $location;
    private readonly NewFeatures $newFeatures;
    private ?Job $job = null;
    private ChoiceSource $source;
    private Input $input;
    private ?Failure $failure            = null;
    private bool $running                = false;
    private bool $signalHandlerInstalled = false;

    /**
     * @var non-negative-int
     */
    private int $numberOfExecutions = 0;

    /**
     * @param positive-int     $maximumNumberOfChoices raised automatically to what the target needs
     * @param non-negative-int $timeout                in seconds, 0 for no time limit
     * @param ?string          $workingDirectory       the directory that paths in locations are relative to, by default the current working directory
     */
    public function __construct(Target $target, int $maximumNumberOfChoices, int $timeout, bool $ignoreDeprecations, ?string $workingDirectory = null)
    {
        $this->location               = new Location($workingDirectory ?? (string) getcwd());
        $this->newFeatures            = new NewFeatures;
        $this->target                 = $target;
        $this->maximumNumberOfChoices = max($maximumNumberOfChoices, $target->requiredNumberOfChoices() ?? 1);
        $this->timeout                = $timeout;
        $this->ignoreDeprecations     = $ignoreDeprecations;
    }

    public function target(): Target
    {
        return $this->target;
    }

    /**
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int
    {
        return $this->maximumNumberOfChoices;
    }

    /**
     * @return non-negative-int
     */
    public function numberOfExecutions(): int
    {
        return $this->numberOfExecutions;
    }

    public function run(Job $job): Result
    {
        $this->numberOfExecutions++;

        Edges::reset();

        $source = $job->source($this->maximumNumberOfChoices);
        $input  = new Input($source, $job->reportDrawnValues());

        $this->job     = $job;
        $this->source  = $source;
        $this->input   = $input;
        $this->failure = null;

        $throwable = null;

        // every issue is reported, unless it is suppressed with @ or the
        // target lowers the error reporting level itself
        $errorReporting = error_reporting(E_ALL);

        set_error_handler($this->handleError(...));

        $this->armAlarm();

        try {
            $this->running = true;

            $this->target->run($input);

            $this->running = false;
        } catch (Throwable $t) {
            $this->running = false;

            $throwable = $t;
        } finally {
            $this->disarmAlarm();

            restore_error_handler();

            error_reporting($errorReporting);
        }

        $edges = Edges::$hits;

        $this->job = null;

        $drawnValues = null;

        if ($job->reportDrawnValues()) {
            $drawnValues = $input->drawnValues();
        }

        $outcome = $this->outcomeOf($input, $throwable);

        if ($outcome->isFinding()) {
            return new Result($job->id(), $outcome, $this->failure ?? $this->failureOf($throwable), $source->sequence(), [], $drawnValues);
        }

        $features = [];

        if ($outcome !== Outcome::Rejected) {
            $features = $job->reportAllFeatures() ? $this->newFeatures->all($edges) : $this->newFeatures->of($edges);
        }

        return new Result($job->id(), $outcome, null, $source->sequence(), $features, $drawnValues);
    }

    /**
     * Returns the result of the input that was executed when the process
     * ended, from a shutdown function: an input that triggered a fatal error,
     * or during which the process exited. Returns null when no input was
     * executed.
     */
    public function resultOfInterruptedExecution(): ?Result
    {
        if ($this->job === null) {
            return null;
        }

        $failure = $this->failure;

        if ($failure === null) {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], self::FATAL_ERRORS, true)) {
                // the message is reported, but it is not part of the signature
                // because messages such as "tried to allocate N bytes" vary
                $failure = Failure::description(
                    'Fatal error at ' . $this->location->ofIssue($error['file'], $error['line']),
                    $error['message'],
                    '',
                );
            } else {
                $failure = Failure::exit();
            }
        }

        $drawnValues = null;

        if ($this->job->reportDrawnValues()) {
            $drawnValues = $this->input->drawnValues();
        }

        return new Result($this->job->id(), Outcome::Failed, $failure, $this->source->sequence(), [], $drawnValues);
    }

    private function outcomeOf(Input $input, ?Throwable $throwable): Outcome
    {
        if ($this->failure !== null) {
            return Outcome::Failed;
        }

        if ($input->isRejected() ||
            $throwable instanceof InputRejectedException ||
            $throwable instanceof TooManyChoicesException) {
            return Outcome::Rejected;
        }

        if ($throwable === null) {
            return Outcome::Passed;
        }

        if ($this->target->isExpected($throwable)) {
            return Outcome::Expected;
        }

        return Outcome::Failed;
    }

    private function failureOf(?Throwable $throwable): Failure
    {
        if ($throwable instanceof TimeoutError) {
            return Failure::timeout($throwable->getMessage(), $this->location->traceOf($throwable));
        }

        if ($throwable === null) {
            // @codeCoverageIgnoreStart
            return Failure::description('Unknown failure', '', '');
            // @codeCoverageIgnoreEnd
        }

        return Failure::throwable(
            $throwable::class,
            $this->location->of($throwable),
            $throwable->getMessage(),
            $this->location->traceOf($throwable),
        );
    }

    private function handleError(int $type, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $type) === 0) {
            return false;
        }

        if ($this->ignoreDeprecations && ($type === E_DEPRECATED || $type === E_USER_DEPRECATED)) {
            return true;
        }

        if (!$this->running || $this->failure !== null) {
            return true;
        }

        $this->failure = Failure::description(
            self::nameOf($type) . ' at ' . $this->location->ofIssue($file, $line),
            $message,
            $this->location->render(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)),
        );

        return true;
    }

    private function armAlarm(): void
    {
        if ($this->timeout === 0) {
            return;
        }

        if (!$this->signalHandlerInstalled) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, $this->handleAlarm(...));

            $this->signalHandlerInstalled = true;
        }

        pcntl_alarm($this->timeout);
    }

    private function disarmAlarm(): void
    {
        if ($this->timeout > 0) {
            pcntl_alarm(0);
        }
    }

    /**
     * Throws a TimeoutError into the code of the target. In case the target
     * catches it, the alarm is armed again, and the execution counts as having
     * exceeded the time limit whatever happens next.
     *
     * @throws TimeoutError
     */
    private function handleAlarm(): void
    {
        if (!$this->running) {
            return;
        }

        $message = sprintf('Execution exceeded the time limit of %d second%s', $this->timeout, $this->timeout === 1 ? '' : 's');

        if ($this->failure === null) {
            $this->failure = Failure::timeout($message, $this->location->render(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        }

        pcntl_alarm(1);

        throw new TimeoutError($message);
    }

    private static function nameOf(int $type): string
    {
        return match ($type) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE                                      => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED                              => 'Deprecated',
            E_USER_ERROR, E_RECOVERABLE_ERROR                            => 'Error',
            default                                                      => 'Issue',
        };
    }
}
