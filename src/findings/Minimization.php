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

use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ShrinkingResult;

/**
 * What happened when an input that made the target fail was reproduced and
 * minimized.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Minimization
{
    private ChoiceSequence $original;
    private ChoiceSequence $minimal;
    private Failure $failure;

    /**
     * @var list<string>
     */
    private array $drawnValues;
    private bool $reproduced;
    private ?ShrinkingResult $shrinking;
    private bool $minimalInputReproduced;

    /**
     * @param list<string> $drawnValues
     */
    public function __construct(ChoiceSequence $original, ChoiceSequence $minimal, Failure $failure, array $drawnValues, bool $reproduced, ?ShrinkingResult $shrinking, bool $minimalInputReproduced)
    {
        $this->original               = $original;
        $this->minimal                = $minimal;
        $this->failure                = $failure;
        $this->drawnValues            = $drawnValues;
        $this->reproduced             = $reproduced;
        $this->shrinking              = $shrinking;
        $this->minimalInputReproduced = $minimalInputReproduced;
    }

    /**
     * The input as it was found.
     */
    public function original(): ChoiceSequence
    {
        return $this->original;
    }

    /**
     * The minimal input, or the input as it was found when it was not
     * minimized or the minimal input did not fail in the same way when it was
     * replayed once more.
     */
    public function minimal(): ChoiceSequence
    {
        return $this->minimal;
    }

    /**
     * How the minimal input made the target fail.
     */
    public function failure(): Failure
    {
        return $this->failure;
    }

    /**
     * The values that the minimal input draws, rendered as PHP code.
     *
     * @return list<string>
     */
    public function drawnValues(): array
    {
        return $this->drawnValues;
    }

    /**
     * Whether replaying the input made the target fail in the same way again.
     * An input that did not is flaky and was not minimized.
     */
    public function isFlaky(): bool
    {
        return !$this->reproduced;
    }

    public function shrinking(): ?ShrinkingResult
    {
        return $this->shrinking;
    }

    /**
     * Whether the minimal input made the target fail in the same way when it
     * was replayed once more. When it did not, the input as it was found is
     * kept.
     */
    public function minimalInputReproduced(): bool
    {
        return $this->minimalInputReproduced;
    }
}
