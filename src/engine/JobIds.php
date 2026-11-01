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
 * Hands out the IDs of inputs, so that the engines that share an executor,
 * one for each property of a PHPUnit test suite, give their inputs IDs that
 * are unique among all of them.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class JobIds
{
    /**
     * @var non-negative-int
     */
    private int $next = 0;

    /**
     * @return non-negative-int
     */
    public function next(): int
    {
        return $this->next++;
    }
}
