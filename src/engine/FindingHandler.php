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
 * Handles the results of inputs that are findings.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This interface is not covered by the backward compatibility promise for sebastian/rattle
 */
interface FindingHandler
{
    /**
     * Returns whether the finding is new, that is, whether its failure
     * signature is not the same as that of a finding that is already known.
     *
     * @param Job $job the input as it was executed
     */
    public function handle(Result $result, Job $job): bool;
}
