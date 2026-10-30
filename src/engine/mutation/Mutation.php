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

use Random\Randomizer;

/**
 * Changes the choices of a candidate. Every list of choices is a valid
 * input: when a list is replayed, a choice that exceeds its range is clamped
 * to the range's maximum, and the choices beyond its end are 0.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This interface is not covered by the backward compatibility promise for sebastian/rattle
 */
interface Mutation
{
    /**
     * Returns false when the mutation cannot be applied to the candidate, for
     * instance because it has no choices.
     */
    public function apply(Candidate $candidate, Randomizer $randomizer): bool;
}
