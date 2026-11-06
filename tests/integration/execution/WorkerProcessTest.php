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

use const SIGKILL;
use function getenv;
use function microtime;
use function posix_kill;
use function str_repeat;
use function strlen;
use function usleep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Starts processes that stand in for workers, with shell commands.
 */
#[CoversClass(WorkerProcess::class)]
#[UsesClass(Frame::class)]
#[UsesClass(FrameBuffer::class)]
#[Medium]
final class WorkerProcessTest extends TestCase
{
    public function testKnowsTheIdOfItsProcessAndTellsHowTheProcessEnded(): void
    {
        $process = $this->start('exec cat');

        $this->assertTrue(posix_kill($process->pid(), 0));

        $process->kill();

        $this->assertSame(['signal' => SIGKILL, 'status' => null], $process->wait());
        $this->assertTrue($process->hasEnded());
    }

    public function testKeepsTheTailOfWhatItsProcessWritesToItsStandardErrorStream(): void
    {
        $process = $this->start('printf "%s" "' . str_repeat('x', 20000) . 'end" >&2');
        $bytes   = '';

        $this->waitForTheEndOf($process);

        // what the process wrote is read in chunks
        while (($chunk = $process->readStderr()) !== '') {
            $bytes .= $chunk;
        }

        $this->assertSame(20003, strlen($bytes));
        $this->assertSame(16384, strlen($process->stderrTail()));
        $this->assertStringEndsWith('xend', $process->stderrTail());
        $this->assertSame(['signal' => null, 'status' => 0], $process->wait());
    }

    public function testDrainsWhatIsLeftInTheStandardErrorStreamOfItsProcess(): void
    {
        $process = $this->start('printf first >&2; sleep 0.1; printf second >&2');

        $this->waitForTheEndOf($process);

        $this->assertSame('firstsecond', $process->drainStderr());
        $this->assertSame(['signal' => null, 'status' => 0], $process->wait());
    }

    public function testSendsNothingToAProcessThatHasEnded(): void
    {
        $process = $this->start('exec true');

        $this->waitForTheEndOf($process);

        $process->send(['type' => 'shutdown']);

        $this->assertSame(['signal' => null, 'status' => 0], $process->wait());
    }

    private function start(string $command): WorkerProcess
    {
        $environment = [];
        $path        = getenv('PATH');

        if ($path !== false) {
            $environment['PATH'] = $path;
        }

        return WorkerProcess::start(['/bin/sh', '-c', $command], $environment);
    }

    /**
     * Waits until the standard output stream of the process ends, which it
     * does when the process ends.
     */
    private function waitForTheEndOf(WorkerProcess $process): void
    {
        $deadline = microtime(true) + 10;

        while ($process->readFrames() !== null) {
            $this->assertLessThan($deadline, microtime(true), 'The process did not end in time');

            usleep(10000);
        }
    }
}
