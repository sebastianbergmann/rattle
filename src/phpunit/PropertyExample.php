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

use function array_is_list;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function sprintf;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\FailureSignature;
use SebastianBergmann\ChoiceSequence\InvalidFailureSignatureException;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

/**
 * An example of a PHPUnit property that PHPUnit ran: how it ended, how it
 * falsified the property, and the choices that it consumed, with their spans
 * and maxima. It is created from the data that PHPUnit returns, which is
 * checked like data from another process.
 *
 * When PHPUnit exported the arguments of the example, the values that it
 * drew, and the steps of its state machine, they are formatted as PHPUnit
 * prints a counterexample, in lines that a worker reports as the drawn values
 * of an input.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PropertyExample
{
    private const array OUTCOMES = ['aborted', 'errored', 'failed', 'incomplete', 'passed', 'rejected', 'skipped', 'timeout'];

    /**
     * @var 'errored'|'failed'|'incomplete'|'passed'|'rejected'|'skipped'|'timeout'
     */
    private string $outcome;
    private ?Failure $failure;
    private ChoiceSequence $sequence;

    /**
     * @var ?list<string>
     */
    private ?array $values;

    /**
     * An example that PHPUnit aborted failed or errored without checking the
     * property, in setUp(), for instance, or because a generator raised an
     * exception while its arguments were generated. As when PHPUnit checks
     * the property, this is the error of the property rather than a finding,
     * and the property cannot be fuzzed.
     *
     * @throws ProtocolException
     * @throws WorkerException   when PHPUnit aborted the example
     */
    public static function fromArray(mixed $data): self
    {
        $data    = ProtocolData::array($data);
        $outcome = $data['outcome'] ?? null;

        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new ProtocolException('Invalid outcome');
        }

        if ($outcome === 'aborted') {
            $failure = self::failureFrom(ProtocolData::array($data['failure'] ?? null));

            throw new WorkerException(
                sprintf(
                    'PHPUnit aborted an example of the property without checking it: %s: %s',
                    $failure->describe(),
                    $failure->message(),
                ),
            );
        }

        $failure = null;

        if ($outcome === 'errored' || $outcome === 'failed' || $outcome === 'timeout') {
            $failure = self::failureFrom(ProtocolData::array($data['failure'] ?? null));
        }

        $choices = ProtocolData::listOfNonNegativeIntegers($data['choices'] ?? null);

        return new self(
            $outcome,
            $failure,
            new ChoiceSequence(
                $choices,
                self::spansFrom($data['spans'] ?? null, count($choices)),
                self::maximaFrom($data['maxima'] ?? null, count($choices)),
            ),
            self::valuesFrom($data),
        );
    }

    /**
     * @param 'errored'|'failed'|'incomplete'|'passed'|'rejected'|'skipped'|'timeout' $outcome
     * @param ?list<string>                                                           $values
     */
    private function __construct(string $outcome, ?Failure $failure, ChoiceSequence $sequence, ?array $values)
    {
        $this->outcome  = $outcome;
        $this->failure  = $failure;
        $this->sequence = $sequence;
        $this->values   = $values;
    }

    /**
     * Passed and rejected are what they are for a fuzz target. A skipped or
     * incomplete example does not tell anything about the code under test,
     * so it counts as rejected.
     */
    public function outcome(): Outcome
    {
        return match ($this->outcome) {
            'passed'                            => Outcome::Passed,
            'rejected', 'skipped', 'incomplete' => Outcome::Rejected,
            'errored', 'failed', 'timeout'      => Outcome::Failed,
        };
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
     * The arguments, drawn values, and steps of the example as PHPUnit
     * prints them for a counterexample, one line for each, with an empty line
     * between them, or null when they were not exported. A line can contain
     * line breaks, because an exported value can.
     *
     * @return ?list<string>
     */
    public function values(): ?array
    {
        return $this->values;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     *
     * @return ?list<string>
     */
    private static function valuesFrom(array $data): ?array
    {
        $arguments   = $data['arguments'] ?? null;
        $drawnValues = $data['drawnValues'] ?? null;
        $steps       = $data['steps'] ?? null;

        if ($arguments === null && $drawnValues === null && $steps === null) {
            return null;
        }

        $lines = [];

        foreach (ProtocolData::array($arguments) as $parameter => $value) {
            $lines[] = sprintf('$%s = %s;', ProtocolData::nonEmptyString($parameter), ProtocolData::string($value));
        }

        $drawnValues = ProtocolData::array($drawnValues);

        if ($lines !== [] && $drawnValues !== []) {
            $lines[] = '';
        }

        foreach (array_values($drawnValues) as $index => $drawnValue) {
            $drawnValue = ProtocolData::array($drawnValue);
            $label      = $drawnValue['label'] ?? null;
            $value      = ProtocolData::string($drawnValue['value'] ?? null);

            if ($label === null) {
                $lines[] = sprintf('Draw %d: %s', $index + 1, $value);
            } else {
                $lines[] = sprintf('Draw %d (%s): %s', $index + 1, ProtocolData::nonEmptyString($label), $value);
            }
        }

        $steps = ProtocolData::listOfStrings($steps);

        if ($lines !== [] && $steps !== []) {
            $lines[] = '';
        }

        foreach ($steps as $step) {
            $lines[] = $step;
        }

        return $lines;
    }

    /**
     * The failure has the failure signature that PHPUnit determined, which
     * is checked, so that rattle and PHPUnit tell the same failures apart.
     *
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     */
    private static function failureFrom(array $data): Failure
    {
        $kind      = ProtocolData::string($data['kind'] ?? null);
        $signature = ProtocolData::nonEmptyString($data['signature'] ?? null);
        $message   = ProtocolData::string($data['message'] ?? null);

        $failure = match ($kind) {
            Failure::THROWABLE   => Failure::throwable(self::className($data['class'] ?? null), ProtocolData::nonEmptyString($data['location'] ?? null), $message, ''),
            Failure::TIMEOUT     => Failure::timeout($message, ''),
            Failure::DESCRIPTION => Failure::description($signature, $message, ''),
            default              => throw new ProtocolException('Invalid kind of failure'),
        };

        try {
            $expected = FailureSignature::fromKindAndText($kind, $signature);
        } catch (InvalidFailureSignatureException $e) {
            throw new ProtocolException('Invalid failure signature: ' . $e->getMessage(), 0, $e);
        }

        if (!$expected->isSameAs($failure->signature())) {
            throw new ProtocolException('The failure signature does not match the failure');
        }

        return $failure;
    }

    /**
     * @throws ProtocolException
     *
     * @return class-string
     */
    private static function className(mixed $value): string
    {
        /** @var class-string */
        return ProtocolData::nonEmptyString($value);
    }

    /**
     * @param non-negative-int $numberOfChoices
     *
     * @throws ProtocolException
     *
     * @return list<Span>
     */
    private static function spansFrom(mixed $data, int $numberOfChoices): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new ProtocolException('Invalid spans');
        }

        $spans = [];

        foreach ($data as $index => $span) {
            $span   = ProtocolData::array($span);
            $start  = $span['start'] ?? null;
            $end    = $span['end'] ?? null;
            $parent = $span['parent'] ?? null;

            if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end > $numberOfChoices ||
                !($parent === null || (is_int($parent) && $parent >= 0 && $parent < $index))) {
                throw new ProtocolException('Invalid span');
            }

            $spans[] = new Span(
                $start,
                $end,
                ProtocolData::nonEmptyString($span['label'] ?? null),
                $parent,
                match ($span['kind'] ?? null) {
                    'bytes'              => SpanKind::Bytes,
                    'characters'         => SpanKind::Characters,
                    'element'            => SpanKind::Element,
                    'requiredelement'    => SpanKind::RequiredElement,
                    'referable'          => SpanKind::Referable,
                    'reference'          => SpanKind::Reference,
                    'consumingreference' => SpanKind::ConsumingReference,
                    null                 => null,
                    default              => throw new ProtocolException('Invalid kind of span'),
                },
            );
        }

        return $spans;
    }

    /**
     * The maxima of all choices, or none when one of them is not known.
     *
     * @param non-negative-int $numberOfChoices
     *
     * @throws ProtocolException
     *
     * @return list<non-negative-int>
     */
    private static function maximaFrom(mixed $data, int $numberOfChoices): array
    {
        if (!is_array($data) || !array_is_list($data) || count($data) !== $numberOfChoices) {
            throw new ProtocolException('Invalid maxima');
        }

        $maxima = [];

        foreach ($data as $maximum) {
            if ($maximum === null) {
                return [];
            }

            if (!is_int($maximum) || $maximum < 0) {
                throw new ProtocolException('Invalid maxima');
            }

            $maxima[] = $maximum;
        }

        return $maxima;
    }
}
