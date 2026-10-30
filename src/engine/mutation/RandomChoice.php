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

use function assert;
use Random\Randomizer;

/**
 * Sets a choice to a random value in [0, maximum], or, when its maximum is
 * not known, flips one of its low bits.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class RandomChoice implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        if ($candidate->length() === 0) {
            return false;
        }

        $index   = $randomizer->getInt(0, $candidate->length() - 1);
        $maximum = $candidate->maximumAt($index);

        if ($maximum !== null) {
            $candidate->set($index, $randomizer->getInt(0, $maximum));

            return true;
        }

        $choice = $candidate->choiceAt($index) ^ (1 << $randomizer->getInt(0, 7));

        assert($choice >= 0);

        $candidate->set($index, $choice);

        return true;
    }
}
