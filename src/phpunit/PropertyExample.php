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
use function count;
use function in_array;
use function is_array;
use function is_int;
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
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PropertyExample
{
    private const array OUTCOMES = ['errored', 'failed', 'incomplete', 'passed', 'rejected', 'skipped', 'timeout'];

    /**
     * @var 'errored'|'failed'|'incomplete'|'passed'|'rejected'|'skipped'|'timeout'
     */
    private string $outcome;
    private ?Failure $failure;
    private ChoiceSequence $sequence;

    /**
     * @throws ProtocolException
     */
    public static function fromArray(mixed $data): self
    {
        $data    = ProtocolData::array($data);
        $outcome = $data['outcome'] ?? null;

        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new ProtocolException('Invalid outcome');
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
        );
    }

    /**
     * @param 'errored'|'failed'|'incomplete'|'passed'|'rejected'|'skipped'|'timeout' $outcome
     */
    private function __construct(string $outcome, ?Failure $failure, ChoiceSequence $sequence)
    {
        $this->outcome  = $outcome;
        $this->failure  = $failure;
        $this->sequence = $sequence;
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
                    'bytes'   => SpanKind::Bytes,
                    'element' => SpanKind::Element,
                    null      => null,
                    default   => throw new ProtocolException('Invalid kind of span'),
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
