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
use function count;
use Closure;
use SebastianBergmann\ChoiceSequence\FailureSignature;

/**
 * Handles the results of inputs that made the target fail.
 *
 * A finding whose failure signature is the same as that of a finding that is
 * known, because it was found before in this run or is stored in the
 * findings directory, is counted as a duplicate. Otherwise, it is reproduced,
 * minimized, and stored. Fuzzing pauses while a finding is minimized.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class FindingProcessor implements FindingHandler
{
    private readonly Minimizer $minimizer;
    private readonly FindingStore $store;
    private readonly TargetDescription $target;
    private readonly string $targetPath;
    private readonly int $seed;

    /**
     * @var ?Closure(Minimization, string): void
     */
    private readonly ?Closure $listener;

    /**
     * @var list<FailureSignature>
     */
    private array $known = [];

    /**
     * @param ?Closure(Minimization, string): void $listener is told about each new finding and the directory it is stored in
     */
    public function __construct(Minimizer $minimizer, FindingStore $store, TargetDescription $target, string $targetPath, int $seed, ?Closure $listener = null)
    {
        $this->minimizer  = $minimizer;
        $this->store      = $store;
        $this->target     = $target;
        $this->targetPath = $targetPath;
        $this->seed       = $seed;
        $this->listener   = $listener;

        $this->known = $store->knownSignatures();
    }

    /**
     * @return non-negative-int
     */
    public function numberOfKnownFindings(): int
    {
        return count($this->known);
    }

    /**
     * @throws FindingException
     */
    public function handle(Result $result, Job $job): bool
    {
        $failure = $result->failure();

        assert($failure !== null);

        $signature = $failure->signature();

        foreach ($this->known as $known) {
            if ($known->isSameAs($signature)) {
                return false;
            }
        }

        $this->known[] = $signature;

        $minimization = $this->minimizer->minimize($result->sequence(), $failure);

        // the failure of the minimal input has the same signature, but its
        // message and stack trace describe the minimal input
        $directory = $this->store->store($minimization, $this->targetPath, $this->target->maximumLength(), $this->seed);

        if ($this->listener !== null) {
            ($this->listener)($minimization, $directory);
        }

        return true;
    }
}
