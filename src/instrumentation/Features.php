<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

/**
 * Turns the edges that an input took into its features: how often an edge
 * was taken is put into one of eight buckets, for 1, 2, 3, 4 to 7, 8 to 15,
 * 16 to 31, 32 to 127, and 128 or more times, and the feature is the number
 * of the bucket, shifted by 56 bits, combined with the edge.
 *
 * An input is interesting when it has a feature that no input had before:
 * when it takes an edge that was not taken before, or takes an edge a number
 * of times that falls into a bucket that was not seen for this edge before.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Features
{
    /**
     * The smallest number of hits that falls into each bucket.
     */
    private const array LOWER_BOUNDS = [1, 2, 3, 4, 8, 16, 32, 128];

    /**
     * @param array<int, positive-int> $hits how often each edge was taken
     *
     * @return list<int>
     */
    public static function fromEdges(array $hits): array
    {
        $features = [];

        foreach ($hits as $edge => $count) {
            $features[] = (self::bucketOf($count) << 56) | $edge;
        }

        return $features;
    }

    /**
     * @param positive-int $count
     *
     * @return int<0, 7>
     */
    public static function bucketOf(int $count): int
    {
        $bucket = 0;

        foreach (self::LOWER_BOUNDS as $index => $lowerBound) {
            if ($count >= $lowerBound) {
                $bucket = $index;
            }
        }

        return $bucket;
    }
}
