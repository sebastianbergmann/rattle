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

use function array_shift;
use Closure;

/**
 * An executor whose results are computed by a closure from the jobs, for
 * instance to report timeouts or dead workers without waiting for them.
 */
final class FakeExecutor implements Executor
{
    /**
     * @var list<Job>
     */
    public array $executed = [];
    public bool $shutDown  = false;

    /**
     * @var Closure(Job): Result
     */
    private readonly Closure $execute;

    /**
     * @var list<Job>
     */
    private array $pending = [];

    /**
     * @param Closure(Job): Result $execute
     */
    public function __construct(Closure $execute)
    {
        $this->execute = $execute;
    }

    public function target(): TargetDescription
    {
        return new TargetDescription('fake', null, 8192);
    }

    public function capacity(): int
    {
        return $this->pending === [] ? 1 : 0;
    }

    public function submit(array $jobs): void
    {
        foreach ($jobs as $job) {
            $this->pending[] = $job;
        }
    }

    public function collect(): array
    {
        $results = [];

        while ($this->pending !== []) {
            $job = array_shift($this->pending);

            $results[] = $this->execute($job);
        }

        return $results;
    }

    public function execute(Job $job): Result
    {
        $this->executed[] = $job;

        return ($this->execute)($job);
    }

    public function shutdown(): void
    {
        $this->shutDown = true;
    }
}
