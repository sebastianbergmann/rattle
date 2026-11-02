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

use SebastianBergmann\Rattle\Instrumentation\Features;

/**
 * The features of an execution that no execution before it in this process
 * had, so that a worker reports every feature only once, unless an input asks
 * for all the features that it produced.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class NewFeatures
{
    /**
     * @var array<int, true>
     */
    private array $seen = [];

    /**
     * @param array<int, positive-int> $hits how often each edge was taken
     *
     * @return list<int>
     */
    public function of(array $hits): array
    {
        return Features::unseen($hits, $this->seen);
    }

    /**
     * All features of an execution, which are seen afterwards, as the ones
     * that of() returns are.
     *
     * @param array<int, positive-int> $hits how often each edge was taken
     *
     * @return list<int>
     */
    public function all(array $hits): array
    {
        $features = Features::fromEdges($hits);

        foreach ($features as $feature) {
            $this->seen[$feature] = true;
        }

        return $features;
    }
}
