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
 * What the supervisor knows about a target, which is loaded by the workers.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class TargetDescription
{
    /**
     * @var non-empty-string
     */
    private string $name;

    /**
     * @var ?positive-int
     */
    private ?int $maximumLength;

    /**
     * @var positive-int
     */
    private int $maximumNumberOfChoices;

    public static function of(Runner $runner): self
    {
        return new self($runner->target()->name(), $runner->target()->maximumLength(), $runner->maximumNumberOfChoices());
    }

    /**
     * @param non-empty-string $name
     * @param ?positive-int    $maximumLength
     * @param positive-int     $maximumNumberOfChoices
     */
    public function __construct(string $name, ?int $maximumLength, int $maximumNumberOfChoices)
    {
        $this->name                   = $name;
        $this->maximumLength          = $maximumLength;
        $this->maximumNumberOfChoices = $maximumNumberOfChoices;
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @phpstan-assert-if-true !null $this->maximumLength()
     */
    public function isBytesTarget(): bool
    {
        return $this->maximumLength !== null;
    }

    /**
     * @return ?positive-int
     */
    public function maximumLength(): ?int
    {
        return $this->maximumLength;
    }

    /**
     * The maximum number of choices of an input, raised to what a bytes
     * target needs.
     *
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int
    {
        return $this->maximumNumberOfChoices;
    }
}
