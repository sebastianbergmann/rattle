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
use function assert;

/**
 * Executes inputs in the current process, without workers, and without
 * protection against fatal errors and exit(). It is used by the tests.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class InProcessExecutor implements Executor
{
    private readonly Runner $runner;

    /**
     * @var list<Job>
     */
    private array $pending = [];

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    public function target(): TargetDescription
    {
        return TargetDescription::of($this->runner);
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

            $results[] = $this->runner->run($job);
        }

        return $results;
    }

    public function execute(Job $job): Result
    {
        assert($this->pending === []);

        return $this->runner->run($job);
    }

    public function shutdown(): void
    {
    }
}
