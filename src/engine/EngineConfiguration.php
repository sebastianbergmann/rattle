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
 * How long the engine fuzzes and how it composes batches of inputs.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class EngineConfiguration
{
    public const int DEFAULT_BATCH_SIZE = 16;

    /**
     * The probability that an input is generated from a fresh seed instead of
     * mutating a corpus entry, once the corpus is not empty.
     */
    public const float PROBABILITY_OF_GENERATION = 0.1;
    private int $seed;

    /**
     * @var ?positive-int
     */
    private ?int $maximumNumberOfExecutions;
    private ?float $maximumTime;
    private bool $stopOnFinding;

    /**
     * @var positive-int
     */
    private int $batchSize;

    /**
     * @param ?positive-int $maximumNumberOfExecutions
     * @param ?float        $maximumTime               in seconds
     * @param positive-int  $batchSize
     */
    public function __construct(int $seed, ?int $maximumNumberOfExecutions = null, ?float $maximumTime = null, bool $stopOnFinding = false, int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        $this->seed                      = $seed;
        $this->maximumNumberOfExecutions = $maximumNumberOfExecutions;
        $this->maximumTime               = $maximumTime;
        $this->stopOnFinding             = $stopOnFinding;
        $this->batchSize                 = $batchSize;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * @return ?positive-int
     */
    public function maximumNumberOfExecutions(): ?int
    {
        return $this->maximumNumberOfExecutions;
    }

    public function maximumTime(): ?float
    {
        return $this->maximumTime;
    }

    public function stopOnFinding(): bool
    {
        return $this->stopOnFinding;
    }

    /**
     * @return positive-int
     */
    public function batchSize(): int
    {
        return $this->batchSize;
    }
}
