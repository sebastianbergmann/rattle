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

/**
 * Executes inputs of a target. The engine submits batches of inputs and
 * collects their results, which arrive one by one and, with more than one
 * worker, in no particular order. The results of a batch arrive in the order
 * of its inputs.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This interface is not covered by the backward compatibility promise for sebastian/rattle
 */
interface Executor
{
    public function target(): TargetDescription;

    /**
     * The number of batches that can be submitted without waiting for
     * results.
     *
     * @return non-negative-int
     */
    public function capacity(): int;

    /**
     * @param non-empty-list<Job> $jobs
     */
    public function submit(array $jobs): void;

    /**
     * Waits until at least one result of the submitted inputs is available and
     * returns the results that are. Returns an empty list when no inputs are
     * pending.
     *
     * @return list<Result>
     */
    public function collect(): array;

    /**
     * Executes one input and waits for its result. No other inputs may be
     * pending.
     */
    public function execute(Job $job): Result;

    public function shutdown(): void;
}
