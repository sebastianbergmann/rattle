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

use function is_bool;
use function is_int;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

/**
 * An input to be executed: a choice sequence that is replayed, or a seed
 * from which the choices are generated at random.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Job
{
    /**
     * @var non-negative-int
     */
    private int $id;

    /**
     * @var ?list<non-negative-int>
     */
    private ?array $choices;
    private ?int $seed;
    private bool $reportDrawnValues;

    /**
     * @param non-negative-int       $id
     * @param list<non-negative-int> $choices
     */
    public static function replay(int $id, array $choices, bool $reportDrawnValues = false): self
    {
        return new self($id, $choices, null, $reportDrawnValues);
    }

    /**
     * @param non-negative-int $id
     */
    public static function generate(int $id, int $seed, bool $reportDrawnValues = false): self
    {
        return new self($id, null, $seed, $reportDrawnValues);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     */
    public static function fromArray(array $data): self
    {
        $id                = $data['id'] ?? null;
        $reportDrawnValues = $data['drawnValues'] ?? null;

        if (!is_int($id) || $id < 0 || !is_bool($reportDrawnValues)) {
            throw new ProtocolException('Invalid input');
        }

        if (isset($data['seed']) && is_int($data['seed'])) {
            return self::generate($id, $data['seed'], $reportDrawnValues);
        }

        return self::replay($id, ProtocolData::listOfNonNegativeIntegers($data['choices'] ?? null), $reportDrawnValues);
    }

    /**
     * @param non-negative-int        $id
     * @param ?list<non-negative-int> $choices
     */
    private function __construct(int $id, ?array $choices, ?int $seed, bool $reportDrawnValues)
    {
        $this->id                = $id;
        $this->choices           = $choices;
        $this->seed              = $seed;
        $this->reportDrawnValues = $reportDrawnValues;
    }

    /**
     * @return non-negative-int
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * @phpstan-assert-if-true !null $this->choices()
     *
     * @phpstan-assert-if-false !null $this->seed()
     */
    public function isReplay(): bool
    {
        return $this->choices !== null;
    }

    /**
     * @return ?list<non-negative-int>
     */
    public function choices(): ?array
    {
        return $this->choices;
    }

    public function seed(): ?int
    {
        return $this->seed;
    }

    public function reportDrawnValues(): bool
    {
        return $this->reportDrawnValues;
    }

    /**
     * The choice sequence that is replayed, or an empty one for an input that
     * is generated.
     */
    public function sequence(): ChoiceSequence
    {
        return new ChoiceSequence($this->choices ?? []);
    }

    /**
     * @param positive-int $maximumNumberOfChoices
     */
    public function source(int $maximumNumberOfChoices): ChoiceSource
    {
        if ($this->choices !== null) {
            return ChoiceSource::replay(new ChoiceSequence($this->choices), $maximumNumberOfChoices);
        }

        return ChoiceSource::random(new Randomizer(new Xoshiro256StarStar((int) $this->seed)), $maximumNumberOfChoices);
    }

    /**
     * @return array{id: non-negative-int, drawnValues: bool, choices?: list<non-negative-int>, seed?: int}
     */
    public function asArray(): array
    {
        if ($this->choices !== null) {
            return ['id' => $this->id, 'drawnValues' => $this->reportDrawnValues, 'choices' => $this->choices];
        }

        return ['id' => $this->id, 'drawnValues' => $this->reportDrawnValues, 'seed' => (int) $this->seed];
    }
}
