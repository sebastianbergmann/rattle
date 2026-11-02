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
 * The features of every execution are computed, so the bucket of a number of
 * hits is looked up in a table, and the features that were not seen before
 * are found in one pass over the edges.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Features
{
    /**
     * The bucket of every number of hits below 128, from which on the number
     * falls into the last bucket, 7.
     */
    private const array BUCKETS = [
        0, 0, 1, 2, 3, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4, 4,
        5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
    ];

    /**
     * @param array<int, positive-int> $hits how often each edge was taken
     *
     * @return list<int>
     */
    public static function fromEdges(array $hits): array
    {
        $buckets  = self::BUCKETS;
        $features = [];

        foreach ($hits as $edge => $count) {
            $features[] = (($count < 128 ? $buckets[$count] : 7) << 56) | $edge;
        }

        return $features;
    }

    /**
     * The features of an execution that took the edges as often as given
     * that are not among the features that were seen, which are added to
     * them.
     *
     * @param array<int, positive-int> $hits how often each edge was taken
     * @param array<int, true>         $seen
     *
     * @param-out array<int, true> $seen
     *
     * @return list<int>
     */
    public static function unseen(array $hits, array &$seen): array
    {
        $buckets  = self::BUCKETS;
        $features = [];

        foreach ($hits as $edge => $count) {
            $feature = (($count < 128 ? $buckets[$count] : 7) << 56) | $edge;

            if (!isset($seen[$feature])) {
                $seen[$feature] = true;
                $features[]     = $feature;
            }
        }

        return $features;
    }

    /**
     * Whether an execution that took the edges as often as given produced
     * all of the features.
     *
     * @param array<int, positive-int> $hits     how often each edge was taken
     * @param list<int>                $features
     */
    public static function produces(array $hits, array $features): bool
    {
        foreach ($features as $feature) {
            $count = $hits[$feature & 0x00FFFFFFFFFFFFFF] ?? null;

            if ($count === null || self::bucketOf($count) !== $feature >> 56) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param positive-int $count
     *
     * @return int<0, 7>
     */
    public static function bucketOf(int $count): int
    {
        return $count < 128 ? self::BUCKETS[$count] : 7;
    }
}
