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
use function max;
use function min;
use Random\Randomizer;

/**
 * Adds a small amount to a choice or subtracts it, clamped to [0, maximum].
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class ArithmeticChoice implements Mutation
{
    private const int LARGEST_AMOUNT = 16;

    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        if ($candidate->length() === 0) {
            return false;
        }

        $index   = $randomizer->getInt(0, $candidate->length() - 1);
        $choice  = $candidate->choiceAt($index);
        $maximum = $candidate->maximumAt($index) ?? PHP_INT_MAX;
        $amount  = $randomizer->getInt(1, self::LARGEST_AMOUNT);

        if ($randomizer->getInt(0, 1) === 0) {
            $candidate->set($index, max(0, $choice - $amount));
        } else {
            $candidate->set($index, $choice > $maximum - $amount ? $maximum : min($maximum, $choice + $amount));
        }

        return true;
    }
}
