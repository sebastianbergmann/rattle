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

use function microtime;

/**
 * What happened while fuzzing so far.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Statistics
{
    /**
     * @var non-negative-int
     */
    public int $executions = 0;

    /**
     * @var non-negative-int
     */
    public int $rejected = 0;

    /**
     * @var non-negative-int
     */
    public int $findings = 0;

    /**
     * @var non-negative-int
     */
    public int $duplicateFindings = 0;

    /**
     * @var non-negative-int
     */
    public int $features = 0;

    /**
     * @var non-negative-int
     */
    public int $corpusEntries = 0;
    private readonly float $start;

    public function __construct(?float $start = null)
    {
        $this->start = $start ?? microtime(true);
    }

    public function elapsed(?float $now = null): float
    {
        return ($now ?? microtime(true)) - $this->start;
    }

    public function executionsPerSecond(?float $now = null): float
    {
        $elapsed = $this->elapsed($now);

        if ($elapsed <= 0.0) {
            return 0.0;
        }

        return $this->executions / $elapsed;
    }
}
