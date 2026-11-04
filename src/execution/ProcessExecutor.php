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

use const INF;
use function array_shift;
use function assert;
use function in_array;
use function is_int;
use function max;
use function microtime;
use function min;
use function sprintf;
use function stream_select;
use function trim;
use Closure;

/**
 * Executes inputs in worker processes, which survive neither fatal errors
 * nor exit() in the code under test, but are replaced when they die.
 *
 * Each worker is sent one batch of inputs at a time and reports one result
 * per input as soon as it is available. Because the supervisor knows which
 * inputs a worker has not reported a result for, a worker whose process ends
 * is attributed to the first of them, the input that it was executing. The
 * other inputs of its batch are sent to the worker that replaces it.
 *
 * A worker that does not report a result within the time limit plus two
 * seconds is killed, and the input it was executing is recorded as exceeding
 * the time limit.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class ProcessExecutor implements Executor
{
    /**
     * How long the supervisor waits for a result beyond the time limit before
     * it kills the worker.
     */
    private const float WATCHDOG_GRACE_PERIOD = 2.0;

    /**
     * How long the supervisor waits for a worker to be ready.
     */
    private const float STARTUP_TIMEOUT = 120.0;

    /**
     * The name of the target of workers that serve all the properties of a
     * PHPUnit test suite.
     */
    private const string TEST_SUITE = 'phpunit';

    /**
     * The name of the target of workers that serve the targets of several
     * target files.
     */
    private const string TARGETS = 'targets';
    private readonly WorkerConfiguration $configuration;

    /**
     * @var positive-int
     */
    private readonly int $numberOfWorkers;

    /**
     * @var ?Closure(int, string): void
     */
    private readonly ?Closure $stderr;
    private readonly PhpBinary $php;

    /**
     * @var array<int, WorkerProcess>
     */
    private array $workers             = [];
    private ?TargetDescription $target = null;

    /**
     * The test IDs of the properties that the workers serve, when they serve
     * all the properties of a PHPUnit test suite.
     *
     * @var ?list<non-empty-string>
     */
    private ?array $properties = null;

    /**
     * The targets that the workers serve, by their names, when they load
     * several target files.
     *
     * @var ?array<non-empty-string, TargetDescription>
     */
    private ?array $targets = null;

    /**
     * The target file of each target that the workers serve, by the names of
     * the targets, when they load several target files.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private array $targetFiles = [];

    /**
     * @var non-negative-int
     */
    private int $numberOfRestarts = 0;

    /**
     * @param positive-int                $numberOfWorkers
     * @param ?Closure(int, string): void $stderr          receives what a worker writes to its standard error stream, with the number of the worker
     * @param ?PhpBinary                  $php             the PHP binary that runs the workers, by default the one that runs rattle
     */
    public function __construct(WorkerConfiguration $configuration, int $numberOfWorkers, ?Closure $stderr = null, ?PhpBinary $php = null)
    {
        $this->configuration   = $configuration;
        $this->numberOfWorkers = $numberOfWorkers;
        $this->stderr          = $stderr;
        $this->php             = $php ?? PhpBinary::current();
    }

    /**
     * The PHP binary that runs the workers.
     */
    public function php(): PhpBinary
    {
        return $this->php;
    }

    /**
     * Starts the workers and waits until they are ready.
     *
     * @throws WorkerException
     */
    public function start(): TargetDescription
    {
        for ($i = 0; $i < $this->numberOfWorkers; $i++) {
            $this->workers[$i] = $this->startWorker($i);
        }

        assert($this->target !== null);

        return $this->target;
    }

    /**
     * The test IDs of the properties that the workers serve, when they serve
     * all the properties of a PHPUnit test suite, or null when they serve one
     * target. The workers are started when they were not started before.
     *
     * @throws WorkerException
     *
     * @return ?list<non-empty-string>
     */
    public function properties(): ?array
    {
        $this->target();

        return $this->properties;
    }

    /**
     * The targets that the workers serve, by their names, in the order of
     * their target files, when the workers load several target files, or
     * null when they serve one target. The workers are started when they
     * were not started before.
     *
     * @throws WorkerException
     *
     * @return ?array<non-empty-string, TargetDescription>
     */
    public function targets(): ?array
    {
        $this->target();

        return $this->targets;
    }

    /**
     * The target file of each target that the workers serve, by the names of
     * the targets, when the workers load several target files.
     *
     * @throws WorkerException
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public function targetFiles(): array
    {
        $this->target();

        return $this->targetFiles;
    }

    public function target(): TargetDescription
    {
        if ($this->target === null) {
            return $this->start();
        }

        return $this->target;
    }

    public function capacity(): int
    {
        $capacity = 0;

        foreach ($this->workers as $worker) {
            if ($worker->isIdle()) {
                $capacity++;
            }
        }

        return $capacity;
    }

    public function submit(array $jobs): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isIdle()) {
                $worker->run($jobs, $this->watchdogTimeout());

                return;
            }
        }

        // @codeCoverageIgnoreStart
        throw new WorkerException('No worker is idle');
        // @codeCoverageIgnoreEnd
    }

    /**
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function collect(): array
    {
        $results = [];

        while ($results === []) {
            if (!$this->hasPendingInputs()) {
                return [];
            }

            $streams = [];

            foreach ($this->workers as $worker) {
                if (!$worker->hasEnded()) {
                    $streams[] = $worker->stdout();
                    $streams[] = $worker->stderr();
                }
            }

            $timeout = max(0.0, min(1.0, $this->earliestDeadline() - microtime(true)));
            $write   = null;
            $except  = null;

            if (@stream_select($streams, $write, $except, 0, (int) ($timeout * 1000000)) === false) {
                // interrupted by a signal, for instance by Ctrl+C
                return [];
            }

            foreach ($this->workers as $index => $worker) {
                if ($worker->hasEnded()) {
                    continue;
                }

                $this->forwardStderr($index, $worker->readStderr());

                try {
                    $frames = $worker->readFrames();
                } catch (ProtocolException) {
                    $worker->corruptedOutput = true;

                    $worker->kill();

                    $frames = null;
                }

                if ($frames === null) {
                    foreach ($this->handleEndOf($index) as $result) {
                        $results[] = $result;
                    }

                    continue;
                }

                foreach ($frames as $frame) {
                    $result = $this->handleFrame($index, $frame);

                    if ($result !== null) {
                        $results[] = $result;
                    }
                }
            }

            $now = microtime(true);

            foreach ($this->workers as $index => $worker) {
                if ($worker->deadline >= $now || $worker->hasEnded()) {
                    continue;
                }

                if ($worker->pending === [] && $worker->exitResult === null) {
                    $worker->kill();

                    foreach ($this->handleEndOf($index) as $result) {
                        $results[] = $result;
                    }

                    continue;
                }

                $results[] = $this->handleTimeoutOf($index);
            }
        }

        return $results;
    }

    /**
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function execute(Job $job): Result
    {
        $this->target();

        // a worker whose process is about to end is replaced first
        while ($this->capacity() === 0) {
            assert(!$this->hasPendingJobs());

            $this->collect();
        }

        $this->submit([$job]);

        while (true) {
            foreach ($this->collect() as $result) {
                if ($result->id() === $job->id()) {
                    return $result;
                }
            }
        }
    }

    public function shutdown(): void
    {
        foreach ($this->workers as $worker) {
            if (!$worker->hasEnded()) {
                $worker->send(['type' => 'shutdown']);
            }
        }

        foreach ($this->workers as $index => $worker) {
            if (!$worker->hasEnded()) {
                if ($worker->pending !== []) {
                    $worker->kill();
                }

                $this->forwardStderr($index, $worker->readStderr() . $worker->drainStderr());

                $worker->wait();
            }
        }

        $this->workers = [];
        $this->target  = null;
    }

    /**
     * How often a worker was replaced because its process ended.
     *
     * @return non-negative-int
     */
    public function numberOfRestarts(): int
    {
        return $this->numberOfRestarts;
    }

    /**
     * @param array<mixed> $frame
     *
     * @throws ProtocolException
     * @throws WorkerException
     */
    private function handleFrame(int $index, array $frame): ?Result
    {
        $worker = $this->worker($index);

        if (($frame['type'] ?? null) === 'error') {
            throw new WorkerException(sprintf('The worker reported an error: %s', ProtocolData::string($frame['message'] ?? null)));
        }

        if (($frame['type'] ?? null) !== 'result') {
            throw new ProtocolException('Unexpected message from a worker');
        }

        $result = Result::fromArray(ProtocolData::array($frame['result'] ?? null));
        $job    = array_shift($worker->pending);

        if ($job === null || $job->id() !== $result->id()) {
            throw new ProtocolException('A worker reported a result for an input that it was not executing');
        }

        if (($frame['last'] ?? false) === true) {
            $worker->reportedLastResult = true;
        }

        if (($frame['retire'] ?? false) === true) {
            $worker->retiring = true;
        }

        if ($result->failure()?->isExit() === true) {
            // the result is completed with the exit status of the process
            $worker->exitResult = $result;

            $result = null;
        }

        // a worker whose process is about to end is killed as well when it
        // does not end in time
        if ($worker->pending === [] && !$worker->isEnding()) {
            $worker->deadline = INF;
        } else {
            $worker->deadline = microtime(true) + $this->watchdogTimeout();
        }

        return $result;
    }

    /**
     * Handles a worker whose standard output stream has ended, and replaces
     * it. A worker that ends while it executes an input without reporting a
     * result for it is attributed to that input. The inputs it did not
     * execute are sent to the worker that replaces it.
     *
     * @throws WorkerException
     *
     * @return list<Result>
     */
    private function handleEndOf(int $index): array
    {
        $worker = $this->worker($index);

        $this->forwardStderr($index, $worker->readStderr());

        $ended   = $worker->wait();
        $results = [];

        if ($worker->exitResult !== null) {
            $results[] = $worker->exitResult->withFailure(Failure::processEnded($ended['signal'], $ended['status']));
        } elseif ($worker->corruptedOutput && $worker->pending !== []) {
            $job = array_shift($worker->pending);

            $results[] = Result::processEnded(
                $job,
                Failure::description(
                    'Invalid output of the worker process',
                    'The code under test wrote to the standard output stream directly, for instance with fwrite(STDOUT, ...), which the worker uses to report results',
                    '',
                ),
            );
        } elseif (!$worker->reportedLastResult && $worker->pending !== []) {
            $job = array_shift($worker->pending);

            $results[] = Result::processEnded($job, Failure::processEnded($ended['signal'], $ended['status']));
        }

        $this->replace($index, $worker->pending);

        return $results;
    }

    /**
     * @throws WorkerException
     */
    private function handleTimeoutOf(int $index): Result
    {
        $worker = $this->worker($index);

        if ($worker->exitResult !== null) {
            // the process did not end after the target called exit()
            $worker->kill();

            $results = $this->handleEndOf($index);

            assert(isset($results[0]));

            return $results[0];
        }

        $worker->kill();
        $worker->wait();

        $job = array_shift($worker->pending);

        assert($job !== null);

        $this->replace($index, $worker->pending);

        return Result::processEnded(
            $job,
            Failure::timeout(
                sprintf('The worker did not report a result within %d seconds and was killed', (int) $this->watchdogTimeout()),
                '',
            ),
        );
    }

    /**
     * @param list<Job> $pending
     *
     * @throws WorkerException
     */
    private function replace(int $index, array $pending): void
    {
        $this->numberOfRestarts++;

        $this->workers[$index] = $this->startWorker($index);

        if ($pending !== []) {
            $this->worker($index)->run($pending, $this->watchdogTimeout());
        }
    }

    /**
     * @throws WorkerException
     */
    private function startWorker(int $index): WorkerProcess
    {
        $worker   = WorkerProcess::forConfiguration($this->configuration, $this->php);
        $deadline = microtime(true) + self::STARTUP_TIMEOUT;

        while (microtime(true) < $deadline) {
            $streams = [$worker->stdout(), $worker->stderr()];
            $write   = null;
            $except  = null;

            if (@stream_select($streams, $write, $except, 1) === false) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $this->forwardStderr($index, $worker->readStderr());

            try {
                $frames = $worker->readFrames();
            } catch (ProtocolException $e) {
                $worker->kill();
                $worker->wait();

                throw new WorkerException(sprintf('The worker could not be started: %s', $e->getMessage()), 0, $e);
            }

            if ($frames === null) {
                $worker->wait();

                throw new WorkerException($this->startupFailure('The worker process ended before it was ready', $worker));
            }

            foreach ($frames as $frame) {
                if (($frame['type'] ?? null) === 'error') {
                    $worker->wait();

                    throw new WorkerException($this->startupFailure(sprintf('The worker could not be started: %s', ProtocolData::string($frame['message'] ?? null)), $worker));
                }

                // a worker that serves all the properties of a PHPUnit test
                // suite lists them instead of describing one target, and a
                // worker that loads several target files describes all their
                // targets
                if (in_array($frame['type'] ?? null, ['ready', 'properties', 'targets'], true)) {
                    $this->handshake($frame, $worker);

                    return $worker;
                }
            }
        }

        $worker->kill();
        $worker->wait();

        throw new WorkerException('The worker was not ready in time');
    }

    /**
     * @param array<mixed> $frame
     *
     * @throws WorkerException
     */
    private function handshake(array $frame, WorkerProcess $worker): void
    {
        if (($frame['protocol'] ?? null) !== Frame::PROTOCOL_VERSION) {
            $worker->kill();
            $worker->wait();

            throw new WorkerException('The worker speaks another version of the protocol');
        }

        if (($frame['type'] ?? null) === 'targets') {
            $this->handshakeOfTargets($frame);

            return;
        }

        $maximumLength          = $frame['maximumLength'] ?? null;
        $maximumNumberOfChoices = $frame['maximumNumberOfChoices'] ?? null;

        if (!is_int($maximumNumberOfChoices) || $maximumNumberOfChoices < 1 ||
            !($maximumLength === null || (is_int($maximumLength) && $maximumLength > 0))) {
            throw new WorkerException('The worker sent an invalid handshake');
        }

        try {
            if (($frame['type'] ?? null) === 'properties') {
                $properties = [];

                foreach (ProtocolData::listOfStrings($frame['properties'] ?? null) as $property) {
                    $properties[] = ProtocolData::nonEmptyString($property);
                }

                $this->properties ??= $properties;
                $this->target ??= new TargetDescription(self::TEST_SUITE, null, $maximumNumberOfChoices);

                return;
            }

            $name = ProtocolData::nonEmptyString($frame['name'] ?? null);
        } catch (ProtocolException $e) {
            throw new WorkerException(sprintf('The worker sent an invalid handshake: %s', $e->getMessage()), 0, $e);
        }

        $this->target ??= new TargetDescription($name, $maximumLength, $maximumNumberOfChoices);
    }

    /**
     * @param array<mixed> $frame
     *
     * @throws WorkerException
     */
    private function handshakeOfTargets(array $frame): void
    {
        $targets                = [];
        $files                  = [];
        $maximumNumberOfChoices = 1;

        try {
            foreach (ProtocolData::array($frame['targets'] ?? null) as $data) {
                $data          = ProtocolData::array($data);
                $name          = ProtocolData::nonEmptyString($data['name'] ?? null);
                $file          = ProtocolData::nonEmptyString($data['file'] ?? null);
                $maximumLength = $data['maximumLength'] ?? null;
                $choices       = $data['maximumNumberOfChoices'] ?? null;

                if (!is_int($choices) || $choices < 1 ||
                    !($maximumLength === null || (is_int($maximumLength) && $maximumLength > 0)) ||
                    isset($targets[$name])) {
                    throw new ProtocolException('Invalid description of a target');
                }

                $targets[$name] = new TargetDescription($name, $maximumLength, $choices);
                $files[$name]   = $file;
            }
        } catch (ProtocolException $e) {
            throw new WorkerException(sprintf('The worker sent an invalid handshake: %s', $e->getMessage()), 0, $e);
        }

        if ($targets === []) {
            throw new WorkerException('The worker sent an invalid handshake: it describes no target');
        }

        foreach ($targets as $target) {
            $maximumNumberOfChoices = max($maximumNumberOfChoices, $target->maximumNumberOfChoices());
        }

        if ($this->targets === null) {
            $this->targets     = $targets;
            $this->targetFiles = $files;
        }

        $this->target ??= new TargetDescription(self::TARGETS, null, $maximumNumberOfChoices);
    }

    private function startupFailure(string $message, WorkerProcess $worker): string
    {
        $stderr = trim($worker->stderrTail());

        if ($stderr === '') {
            return $message;
        }

        return $message . "\n\n" . $stderr;
    }

    private function forwardStderr(int $index, string $bytes): void
    {
        if ($bytes !== '' && $this->stderr !== null) {
            ($this->stderr)($index, $bytes);
        }
    }

    private function worker(int $index): WorkerProcess
    {
        assert(isset($this->workers[$index]));

        return $this->workers[$index];
    }

    private function hasPendingInputs(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker->pending !== [] || $worker->isEnding()) {
                return true;
            }
        }

        return false;
    }

    private function hasPendingJobs(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker->pending !== [] || $worker->exitResult !== null) {
                return true;
            }
        }

        return false;
    }

    private function earliestDeadline(): float
    {
        $deadline = INF;

        foreach ($this->workers as $worker) {
            $deadline = min($deadline, $worker->deadline);
        }

        return $deadline;
    }

    private function watchdogTimeout(): float
    {
        return $this->configuration->timeout() + self::WATCHDOG_GRACE_PERIOD;
    }
}
