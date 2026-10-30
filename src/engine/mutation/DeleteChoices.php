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

use function min;
use Random\Randomizer;

/**
 * Deletes a block of consecutive choices, or cuts off the tail.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DeleteChoices implements Mutation
{
    private const int LARGEST_BLOCK = 16;

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $length = $candidate->length();

        if ($length === 0) {
            return false;
        }

        $start = $randomizer->getInt(0, $length - 1);

        if ($randomizer->getInt(0, 1) === 0) {
            $candidate->truncate($start);

            return true;
        }

        $candidate->delete($start, $randomizer->getInt(1, min(self::LARGEST_BLOCK, $length - $start)));

        return true;
    }
}
