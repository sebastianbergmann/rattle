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
 * Executes inputs in a worker process: the inputs of a fuzz target, or the
 * examples of a PHPUnit property.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This interface is not covered by the backward compatibility promise for sebastian/rattle
 */
interface InputRunner
{
    /**
     * @throws WorkerException when the input cannot be executed at all
     */
    public function run(Job $job): Result;

    /**
     * Returns the result of the input that was executed when the process
     * ended, from a shutdown function, or null when no input was executed.
     */
    public function resultOfInterruptedExecution(): ?Result;

    /**
     * @return non-negative-int
     */
    public function numberOfExecutions(): int;

    /**
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int;
}
