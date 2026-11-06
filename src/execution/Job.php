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
 * The result of an input has the features that it produced and that the
 * process that executed it had not seen before, unless the input asks for
 * all the features that it produced. An input can also require features, and
 * its result tells whether it produced them all.
 *
 * An input for a worker that serves all the properties of a PHPUnit test
 * suite names the property whose example it is.
 *
 * The result of a terse input has the choice sequence that the input
 * consumed, with its spans and maxima, only when it is needed: when the input
 * failed, produced features, or produced the features that it required. The
 * engine sends terse inputs, as most of their results are of no interest, and
 * encoding, sending, and decoding their sequences takes time.
 *
 * An input can ask for its coverage: its result then identifies the path
 * that the input took through the code that is instrumented, whatever its
 * outcome, and has the blocks of the files that were instrumented since the
 * previous result (see Result).
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
    private bool $reportAllFeatures;

    /**
     * @var list<int>
     */
    private array $requiredFeatures;

    /**
     * @var ?non-empty-string
     */
    private ?string $target;
    private bool $terse;
    private bool $reportCoverage;

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
        $reportAllFeatures = $data['allFeatures'] ?? false;
        $requiredFeatures  = ProtocolData::listOfIntegers($data['requiredFeatures'] ?? []);
        $target            = isset($data['target']) ? ProtocolData::nonEmptyString($data['target']) : null;
        $terse             = $data['terse'] ?? false;
        $reportCoverage    = $data['coverage'] ?? false;

        if (!is_int($id) || $id < 0 || !is_bool($reportDrawnValues) || !is_bool($reportAllFeatures) || !is_bool($terse) || !is_bool($reportCoverage)) {
            throw new ProtocolException('Invalid input');
        }

        if (isset($data['seed']) && is_int($data['seed'])) {
            return new self($id, null, $data['seed'], $reportDrawnValues, $reportAllFeatures, $requiredFeatures, $target, $terse, $reportCoverage);
        }

        return new self($id, ProtocolData::listOfNonNegativeIntegers($data['choices'] ?? null), null, $reportDrawnValues, $reportAllFeatures, $requiredFeatures, $target, $terse, $reportCoverage);
    }

    /**
     * @param non-negative-int        $id
     * @param ?list<non-negative-int> $choices
     * @param list<int>               $requiredFeatures
     * @param ?non-empty-string       $target
     */
    private function __construct(int $id, ?array $choices, ?int $seed, bool $reportDrawnValues, bool $reportAllFeatures = false, array $requiredFeatures = [], ?string $target = null, bool $terse = false, bool $reportCoverage = false)
    {
        $this->id                = $id;
        $this->choices           = $choices;
        $this->seed              = $seed;
        $this->reportDrawnValues = $reportDrawnValues;
        $this->reportAllFeatures = $reportAllFeatures;
        $this->requiredFeatures  = $requiredFeatures;
        $this->target            = $target;
        $this->terse             = $terse;
        $this->reportCoverage    = $reportCoverage;
    }

    /**
     * The same input, whose result has all the features that it produced.
     */
    public function withAllFeatures(): self
    {
        return new self($this->id, $this->choices, $this->seed, $this->reportDrawnValues, true, $this->requiredFeatures, $this->target, $this->terse, $this->reportCoverage);
    }

    /**
     * The same input, whose result tells whether it produced all of the
     * features.
     *
     * @param list<int> $features
     */
    public function withRequiredFeatures(array $features): self
    {
        return new self($this->id, $this->choices, $this->seed, $this->reportDrawnValues, $this->reportAllFeatures, $features, $this->target, $this->terse, $this->reportCoverage);
    }

    /**
     * The same input, as an example of the property with the given test ID.
     *
     * @param non-empty-string $target
     */
    public function withTarget(string $target): self
    {
        return new self($this->id, $this->choices, $this->seed, $this->reportDrawnValues, $this->reportAllFeatures, $this->requiredFeatures, $target, $this->terse, $this->reportCoverage);
    }

    /**
     * The input, whose result has its choice sequence only when it is needed.
     */
    public function terse(): self
    {
        return new self($this->id, $this->choices, $this->seed, $this->reportDrawnValues, $this->reportAllFeatures, $this->requiredFeatures, $this->target, true, $this->reportCoverage);
    }

    /**
     * The same input, whose result identifies the path that it took and has
     * the blocks of the files that were instrumented since the previous
     * result.
     */
    public function withCoverage(): self
    {
        return new self($this->id, $this->choices, $this->seed, $this->reportDrawnValues, $this->reportAllFeatures, $this->requiredFeatures, $this->target, $this->terse, true);
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

    public function reportAllFeatures(): bool
    {
        return $this->reportAllFeatures;
    }

    /**
     * @return list<int>
     */
    public function requiredFeatures(): array
    {
        return $this->requiredFeatures;
    }

    /**
     * The test ID of the property whose example the input is, or null.
     *
     * @return ?non-empty-string
     */
    public function target(): ?string
    {
        return $this->target;
    }

    public function isTerse(): bool
    {
        return $this->terse;
    }

    public function reportCoverage(): bool
    {
        return $this->reportCoverage;
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
     * @return array{id: non-negative-int, drawnValues: bool, target?: non-empty-string, allFeatures?: true, requiredFeatures?: non-empty-list<int>, terse?: true, coverage?: true, choices?: list<non-negative-int>, seed?: int}
     */
    public function asArray(): array
    {
        $data = ['id' => $this->id, 'drawnValues' => $this->reportDrawnValues];

        if ($this->target !== null) {
            $data['target'] = $this->target;
        }

        if ($this->reportAllFeatures) {
            $data['allFeatures'] = true;
        }

        if ($this->requiredFeatures !== []) {
            $data['requiredFeatures'] = $this->requiredFeatures;
        }

        if ($this->terse) {
            $data['terse'] = true;
        }

        if ($this->reportCoverage) {
            $data['coverage'] = true;
        }

        if ($this->choices !== null) {
            $data['choices'] = $this->choices;
        } else {
            $data['seed'] = (int) $this->seed;
        }

        return $data;
    }
}
