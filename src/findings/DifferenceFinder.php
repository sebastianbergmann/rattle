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
 * Runs the inputs that the engine adds to the corpus, and the new findings,
 * on all versions of PHP (see DifferentialExecutor), and passes a difference
 * to a finding handler, which minimizes and stores it as a finding of its
 * own. Comparing the inputs that reach code that no input reached before,
 * rather than every input, keeps the cost proportional to the corpus.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DifferenceFinder
{
    private DifferentialExecutor $executor;
    private FindingHandler $findings;

    public function __construct(DifferentialExecutor $executor, FindingHandler $findings)
    {
        $this->executor = $executor;
        $this->findings = $findings;
    }

    /**
     * Returns whether the input differs between the versions in a way that is
     * a new finding (true) or a known one (false), or null when it does not.
     *
     * @throws FindingException
     */
    public function check(Result $result, Job $job): ?bool
    {
        $difference = $this->executor->execute(Job::replay($job->id(), $result->sequence()->choices()));

        if (!$difference->isFinding()) {
            return null;
        }

        return $this->findings->handle($difference, $job);
    }
}
