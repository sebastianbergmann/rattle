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

use const PHP_INT_MAX;
use Random\Randomizer;

/**
 * Sets a choice to 0 or to its maximum. When the maximum is not known, the
 * largest integer is used, which replaying clamps to the maximum.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class BoundaryChoice implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        if ($candidate->length() === 0) {
            return false;
        }

        $index = $randomizer->getInt(0, $candidate->length() - 1);

        if ($randomizer->getInt(0, 1) === 0) {
            $candidate->set($index, 0);
        } else {
            $candidate->set($index, $candidate->maximumAt($index) ?? PHP_INT_MAX);
        }

        return true;
    }
}
