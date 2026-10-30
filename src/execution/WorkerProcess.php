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
use const SIGKILL;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_set_blocking;
use function strlen;
use function substr;
use function usleep;

/**
 * The supervisor's handle of a worker process: its pipes, the frames it has
 * sent in part, and the inputs that were sent to it without a result yet.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class WorkerProcess
{
    private const int MAXIMUM_LENGTH_OF_STDERR_TAIL = 16384;

    /**
     * The inputs that were sent to the worker and for which it has not
     * reported a result yet, in the order in which they were sent.
     *
     * @var list<Job>
     */
    public array $pending = [];

    /**
     * When the worker is killed unless it reports a result before.
     */
    public float $deadline = INF;

    /**
     * The result that the worker reported for an input during which its
     * process exited, which is completed with the exit status.
     */
    public ?Result $exitResult = null;

    /**
     * Whether the worker reported the last result before its process ends,
     * from its shutdown function.
     */
    public bool $reportedLastResult = false;

    /**
     * Whether the worker announced that it exits after the batch it was
     * executing, to limit state that leaks through static properties.
     */
    public bool $retiring = false;

    /**
     * @var resource
     */
    private readonly mixed $process;

    /**
     * @var resource
     */
    private readonly mixed $stdin;

    /**
     * @var resource
     */
    private readonly mixed $stdout;

    /**
     * @var resource
     */
    private readonly mixed $stderr;
    private readonly FrameBuffer $buffer;
    private readonly int $pid;
    private string $stderrTail = '';
    private bool $ended        = false;

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>  $environment
     *
     * @throws WorkerException
     */
    public static function start(array $command, array $environment): self
    {
        $pipes   = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $environment,
        );

        if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
            // @codeCoverageIgnoreStart
            throw new WorkerException('Cannot start a worker process');
            // @codeCoverageIgnoreEnd
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[0], $pipes[1], $pipes[2]);
    }

    /**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private function __construct(mixed $process, mixed $stdin, mixed $stdout, mixed $stderr)
    {
        $this->process = $process;
        $this->stdin   = $stdin;
        $this->stdout  = $stdout;
        $this->stderr  = $stderr;
        $this->buffer  = new FrameBuffer;
        $this->pid     = proc_get_status($process)['pid'];
    }

    /**
     * @return resource
     */
    public function stdout(): mixed
    {
        return $this->stdout;
    }

    /**
     * @return resource
     */
    public function stderr(): mixed
    {
        return $this->stderr;
    }

    public function isIdle(): bool
    {
        return !$this->ended && $this->pending === [] && !$this->isEnding();
    }

    /**
     * Whether the process of the worker is about to end, after it reported
     * the last result of the input during which it ends, or the last result of
     * the batch after which it retires.
     */
    public function isEnding(): bool
    {
        return !$this->ended && ($this->reportedLastResult || $this->retiring || $this->exitResult !== null);
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message): void
    {
        $frame = Frame::encode($message);

        while ($frame !== '') {
            $written = @fwrite($this->stdin, $frame);

            if ($written === false || $written === 0) {
                // the process has ended, which is noticed when its standard
                // output stream ends
                return;
            }

            $frame = substr($frame, $written);
        }
    }

    /**
     * @param list<Job> $jobs
     */
    public function run(array $jobs, float $timeout): void
    {
        $inputs = [];

        foreach ($jobs as $job) {
            $inputs[] = $job->asArray();
        }

        $this->pending  = $jobs;
        $this->deadline = microtime(true) + $timeout;

        $this->send(['type' => 'run', 'inputs' => $inputs]);
    }

    /**
     * Reads what is available on the standard output stream and returns the
     * frames that are complete. Returns null when the stream has ended.
     *
     * @throws ProtocolException
     *
     * @return ?list<array<mixed>>
     */
    public function readFrames(): ?array
    {
        $bytes = fread($this->stdout, 65536);

        if ($bytes === false || ($bytes === '' && feof($this->stdout))) {
            return null;
        }

        $this->buffer->append($bytes);

        return $this->buffer->frames();
    }

    /**
     * Reads what is available on the standard error stream.
     */
    public function readStderr(): string
    {
        $bytes = fread($this->stderr, 65536);

        if ($bytes === false) {
            return '';
        }

        $this->stderrTail .= $bytes;

        if (strlen($this->stderrTail) > self::MAXIMUM_LENGTH_OF_STDERR_TAIL) {
            $this->stderrTail = substr($this->stderrTail, -self::MAXIMUM_LENGTH_OF_STDERR_TAIL);
        }

        return $bytes;
    }

    /**
     * The last bytes that the worker wrote to its standard error stream.
     */
    public function stderrTail(): string
    {
        return $this->stderrTail;
    }

    /**
     * Reads the standard error stream until it ends, after the worker was
     * told to shut down.
     */
    public function drainStderr(): string
    {
        stream_set_blocking($this->stderr, true);

        $bytes = '';

        while (!feof($this->stderr)) {
            $chunk = fread($this->stderr, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }

    public function kill(): void
    {
        if (!$this->ended) {
            proc_terminate($this->process, SIGKILL);
        }
    }

    /**
     * Waits for the process to end and returns the signal that terminated it
     * or its exit status.
     *
     * @return array{signal: ?int, status: ?int}
     */
    public function wait(): array
    {
        $this->ended = true;

        for ($attempt = 0; $attempt < 500; $attempt++) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                $this->close();

                if ($status['signaled']) {
                    return ['signal' => $status['termsig'], 'status' => null];
                }

                return ['signal' => null, 'status' => $status['exitcode']];
            }

            usleep(10000);
        }

        proc_terminate($this->process, SIGKILL);

        $this->close();

        return ['signal' => SIGKILL, 'status' => null];
    }

    public function pid(): int
    {
        return $this->pid;
    }

    private function close(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($this->process);
    }
}
