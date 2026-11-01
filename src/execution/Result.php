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

use function array_map;
use function assert;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_int;
use function is_string;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * What happened when an input was executed: its outcome, how it made the
 * target fail, the choice sequence it consumed, with spans and maxima, the
 * features it produced that the process that executed it had not seen
 * before, and, when asked for, the values that were drawn.
 *
 * A result also has the constant operands of comparisons that the process
 * found in the code that it instrumented and had not reported before, which
 * are tokens for the dictionary. They are base64-encoded for the protocol,
 * because they are often not valid UTF-8.
 *
 * When the input required features, the result tells whether it produced
 * them all.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Result
{
    /**
     * @var non-negative-int
     */
    private int $id;
    private Outcome $outcome;
    private ?Failure $failure;
    private ChoiceSequence $sequence;

    /**
     * @var list<int>
     */
    private array $features;

    /**
     * @var ?list<string>
     */
    private ?array $drawnValues;

    /**
     * @var list<non-empty-string>
     */
    private array $tokens;
    private bool $producedRequiredFeatures;

    /**
     * The result of an input during which the process of a worker ended
     * without reporting a result. The consumed sequence is not known, so the
     * input as it was sent stands in for it.
     */
    public static function processEnded(Job $job, Failure $failure): self
    {
        return new self($job->id(), Outcome::Failed, $failure, $job->sequence(), [], null);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;

        if (!is_int($id) || $id < 0) {
            throw new ProtocolException('Invalid id of a result');
        }

        $outcome = Outcome::tryFrom(ProtocolData::string($data['outcome'] ?? null));

        if ($outcome === null) {
            throw new ProtocolException('Invalid outcome of a result');
        }

        $failure = null;

        if (isset($data['failure'])) {
            $failure = Failure::fromArray(ProtocolData::array($data['failure']));
        }

        $drawnValues = null;

        if (isset($data['drawnValues'])) {
            $drawnValues = ProtocolData::listOfStrings($data['drawnValues']);
        }

        $tokens = [];

        foreach (ProtocolData::listOfStrings($data['tokens'] ?? []) as $token) {
            $token = base64_decode($token, true);

            if ($token === false || $token === '') {
                throw new ProtocolException('Invalid token');
            }

            $tokens[] = $token;
        }

        return new self(
            $id,
            $outcome,
            $failure,
            self::sequenceFromArray(ProtocolData::array($data['sequence'] ?? null)),
            ProtocolData::listOfIntegers($data['features'] ?? null),
            $drawnValues,
            $tokens,
            ($data['producedRequiredFeatures'] ?? false) === true,
        );
    }

    /**
     * @param non-negative-int       $id
     * @param list<int>              $features
     * @param ?list<string>          $drawnValues
     * @param list<non-empty-string> $tokens
     * @param bool                   $producedRequiredFeatures whether the input produced all the features that it required
     */
    public function __construct(int $id, Outcome $outcome, ?Failure $failure, ChoiceSequence $sequence, array $features, ?array $drawnValues, array $tokens = [], bool $producedRequiredFeatures = false)
    {
        assert(($failure !== null) === $outcome->isFinding());

        $this->id          = $id;
        $this->outcome     = $outcome;
        $this->failure     = $failure;
        $this->sequence    = $sequence;
        $this->features    = $features;
        $this->drawnValues = $drawnValues;
        $this->tokens      = $tokens;

        $this->producedRequiredFeatures = $producedRequiredFeatures;
    }

    /**
     * @return non-negative-int
     */
    public function id(): int
    {
        return $this->id;
    }

    public function outcome(): Outcome
    {
        return $this->outcome;
    }

    /**
     * @phpstan-assert-if-true !null $this->failure()
     */
    public function isFinding(): bool
    {
        return $this->failure !== null;
    }

    public function failure(): ?Failure
    {
        return $this->failure;
    }

    public function sequence(): ChoiceSequence
    {
        return $this->sequence;
    }

    /**
     * @return list<int>
     */
    public function features(): array
    {
        return $this->features;
    }

    /**
     * @return ?list<string>
     */
    public function drawnValues(): ?array
    {
        return $this->drawnValues;
    }

    /**
     * The constant operands of comparisons that were found in code that was
     * instrumented and were not reported before.
     *
     * @return list<non-empty-string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Whether the input produced all the features that it required.
     */
    public function producedRequiredFeatures(): bool
    {
        return $this->producedRequiredFeatures;
    }

    public function withFailure(Failure $failure): self
    {
        return new self($this->id, Outcome::Failed, $failure, $this->sequence, $this->features, $this->drawnValues, $this->tokens, $this->producedRequiredFeatures);
    }

    /**
     * @param list<non-empty-string> $tokens
     */
    public function withTokens(array $tokens): self
    {
        return new self($this->id, $this->outcome, $this->failure, $this->sequence, $this->features, $this->drawnValues, $tokens, $this->producedRequiredFeatures);
    }

    /**
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        $spans  = [];
        $maxima = [];

        foreach ($this->sequence->spans() as $span) {
            $spans[] = [$span->start(), $span->end(), $span->label(), $span->parent(), $span->kind()?->name];
        }

        foreach ($this->sequence->choices() as $index => $choice) {
            $maxima[] = $this->sequence->maximumAt($index);
        }

        $data = [
            'id'       => $this->id,
            'outcome'  => $this->outcome->value,
            'sequence' => [
                'choices' => $this->sequence->choices(),
                'spans'   => $spans,
                'maxima'  => $maxima,
            ],
            'features' => $this->features,
        ];

        if ($this->failure !== null) {
            $data['failure'] = $this->failure->asArray();
        }

        if ($this->drawnValues !== null) {
            $data['drawnValues'] = $this->drawnValues;
        }

        if ($this->tokens !== []) {
            $data['tokens'] = array_map(base64_encode(...), $this->tokens);
        }

        if ($this->producedRequiredFeatures) {
            $data['producedRequiredFeatures'] = true;
        }

        return $data;
    }

    /**
     * @throws ProtocolException
     */
    private static function kindOf(mixed $name): ?SpanKind
    {
        if ($name === null) {
            return null;
        }

        foreach (SpanKind::cases() as $kind) {
            if ($kind->name === $name) {
                return $kind;
            }
        }

        throw new ProtocolException('Invalid kind of span');
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     */
    private static function sequenceFromArray(array $data): ChoiceSequence
    {
        $choices = ProtocolData::listOfNonNegativeIntegers($data['choices'] ?? null);
        $spans   = [];

        foreach (ProtocolData::array($data['spans'] ?? null) as $span) {
            if (!is_array($span) ||
                !isset($span[0], $span[1], $span[2]) ||
                !is_int($span[0]) || $span[0] < 0 ||
                !is_int($span[1]) || $span[1] <= $span[0] ||
                !is_string($span[2]) || $span[2] === '') {
                throw new ProtocolException('Invalid span');
            }

            $parent = $span[3] ?? null;

            if ($parent !== null && (!is_int($parent) || $parent < 0)) {
                throw new ProtocolException('Invalid span');
            }

            $spans[] = new Span($span[0], $span[1], $span[2], $parent, self::kindOf($span[4] ?? null));
        }

        $maxima = [];

        foreach (ProtocolData::array($data['maxima'] ?? null) as $maximum) {
            if ($maximum === null) {
                // maxima are known for all choices or for none
                $maxima = [];

                break;
            }

            if (!is_int($maximum) || $maximum < 0) {
                throw new ProtocolException('Invalid maximum');
            }

            $maxima[] = $maximum;
        }

        return new ChoiceSequence($choices, $spans, $maxima);
    }
}
