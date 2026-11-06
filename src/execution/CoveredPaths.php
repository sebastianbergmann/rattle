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

use function hash;
use function pack;
use SebastianBergmann\Rattle\Instrumentation\Features;

/**
 * The paths that executions in this process took through the code that is
 * instrumented, so that a worker reports the features of every path only
 * once, as the results of inputs that ask for their coverage identify the
 * paths that they took.
 *
 * A path is the features of an execution in the order in which their edges
 * were first taken: which edges it took, and into which bucket the number of
 * times that it took each of them falls. It is identified by a hash of them.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class CoveredPaths
{
    /**
     * @var array<string, true>
     */
    private array $reported = [];

    /**
     * The result with the path of the execution that took the edges as often
     * as given, with its features when the path was not reported before.
     *
     * @param array<int, positive-int> $hits how often each edge was taken, in the order in which the edges were first taken
     */
    public function addTo(Result $result, array $hits): Result
    {
        $features = Features::fromEdges($hits);
        $path     = hash('xxh128', pack('J*', ...$features));

        if (isset($this->reported[$path])) {
            return $result->withPath($path, null);
        }

        $this->reported[$path] = true;

        return $result->withPath($path, $features);
    }
}
