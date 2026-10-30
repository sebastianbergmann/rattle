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

use function array_keys;
use function assert;
use function count;
use Random\Randomizer;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class RandomElement
{
    /**
     * Chooses one of the elements uniformly at random.
     *
     * @template T
     *
     * @param non-empty-array<T> $elements
     *
     * @return T
     */
    public static function of(Randomizer $randomizer, array $elements): mixed
    {
        $keys = array_keys($elements);
        $key  = $keys[$randomizer->getInt(0, count($keys) - 1)] ?? null;

        assert($key !== null && isset($elements[$key]));

        return $elements[$key];
    }
}
