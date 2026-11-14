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
use function assert;
use function count;
use function sprintf;
use SebastianBergmann\ChoiceSequence\BernoulliDistribution;
use SebastianBergmann\ChoiceSequence\BoundaryDistribution;
use SebastianBergmann\ChoiceSequence\Bytes;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\Label;
use SebastianBergmann\ChoiceSequence\SpanKind;
use SebastianBergmann\ChoiceSequence\TooManyChoicesException;
use SebastianBergmann\ChoiceSequence\UniformDistribution;

/**
 * Provides the values that a structured target draws. Every value is drawn
 * from choices in the same way as PHPUnit's generators draw it, so that
 * inputs are shrunk in the same way.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 */
final class Input
{
    private readonly ChoiceSource $source;
    private readonly bool $recordDrawnValues;

    /**
     * @var list<string>
     */
    private array $drawnValues = [];
    private bool $rejected     = false;

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     */
    public function __construct(ChoiceSource $source, bool $recordDrawnValues)
    {
        $this->source            = $source;
        $this->recordDrawnValues = $recordDrawnValues;
    }

    /**
     * Draws a binary string of at most $maxLength bytes. The empty string is
     * the simplest one.
     *
     * @throws InputRejectedException
     * @throws InvalidArgumentException
     */
    public function bytes(int $maxLength): string
    {
        if ($maxLength < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'The maximum length must not be negative, %d given',
                    $maxLength,
                ),
            );
        }

        $encoding = new Bytes(0, $maxLength);
        $bytes    = '';

        $this->draw(
            $encoding->label(),
            function () use ($encoding, &$bytes): void
            {
                $bytes = $encoding->draw($this->source);
            },
            SpanKind::Bytes,
        );

        $this->record($bytes);

        return $bytes;
    }

    /**
     * Draws an integer in the range [$min, $max]. The value closest to zero
     * is the simplest one.
     *
     * A range that contains negative and non-negative values is drawn as two
     * choices, as by PHPUnit's IntegerGenerator: the first selects the
     * non-negative (0) or the negative (1) half, the second the value within
     * it. In the negative half, the choice c stands for the value ~c, so that
     * neither half can overflow.
     *
     * @throws InputRejectedException
     * @throws InvalidArgumentException
     */
    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException(
                sprintf(
                    'The minimum %d must not be greater than the maximum %d',
                    $min,
                    $max,
                ),
            );
        }

        $value = 0;

        $this->draw(
            Label::of('integers', (string) $min, (string) $max),
            function () use ($min, $max, &$value): void
            {
                $magnitude = new BoundaryDistribution;

                if ($min >= 0) {
                    $range = $max - $min;

                    assert($range >= 0);

                    $value = $min + $this->source->draw($range, $magnitude);

                    return;
                }

                if ($max < 0) {
                    $range = $max - $min;

                    assert($range >= 0);

                    $value = $max - $this->source->draw($range, $magnitude);

                    return;
                }

                if ($this->source->draw(1, new UniformDistribution) === 0) {
                    $value = $this->source->draw($max, $magnitude);

                    return;
                }

                $value = ~$this->source->draw(~$min, $magnitude);
            },
        );

        $this->record($value);

        return $value;
    }

    /**
     * Draws a boolean. false is the simplest one.
     *
     * @throws InputRejectedException
     */
    public function bool(): bool
    {
        $value = false;

        $this->draw(
            Label::of('booleans'),
            function () use (&$value): void
            {
                $value = $this->source->draw(1, new BernoulliDistribution(0.5)) === 1;
            },
        );

        $this->record($value);

        return $value;
    }

    /**
     * Draws one of the elements. The first element is the simplest one.
     *
     * @template T
     *
     * @param array<T> $elements
     *
     * @throws InputRejectedException
     * @throws InvalidArgumentException
     *
     * @return T
     */
    public function pick(array $elements): mixed
    {
        if ($elements === []) {
            throw new InvalidArgumentException('Cannot pick an element of an empty array');
        }

        if (!array_is_list($elements)) {
            $elements = array_values($elements);
        }

        $value = $elements[0];

        $this->draw(
            Label::of('elements', (string) count($elements)),
            function () use ($elements, &$value): void
            {
                $value = $this->source->choose($elements, new UniformDistribution);
            },
        );

        $this->record($value);

        return $value;
    }

    /**
     * Discards the input, for instance because the values that were drawn do
     * not fit together. A rejected input is not a finding.
     *
     * @throws InputRejectedException
     */
    public function reject(): never
    {
        $this->rejected = true;

        throw InputRejectedException::byTarget();
    }

    /**
     * Whether the input was rejected, even if the target caught the exception
     * that rejected it.
     *
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * The values that were drawn, rendered as PHP code, when they are recorded.
     *
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @return list<string>
     */
    public function drawnValues(): array
    {
        return $this->drawnValues;
    }

    /**
     * Draws the choices of a value in a span with the given label. When more
     * choices are drawn than permitted, the input is rejected, as in PHPUnit.
     *
     * @param non-empty-string $label
     * @param callable(): void $draw
     *
     * @throws InputRejectedException
     */
    private function draw(string $label, callable $draw, ?SpanKind $kind = null): void
    {
        $this->source->startSpan($label, $kind);

        try {
            $draw();
        } catch (TooManyChoicesException $e) {
            $this->rejected = true;

            throw InputRejectedException::tooManyChoices($e->maximumNumberOfChoices());
        }

        $this->source->endSpan();
    }

    private function record(mixed $value): void
    {
        if (!$this->recordDrawnValues) {
            return;
        }

        $this->drawnValues[] = (new ValueExporter)->export($value);
    }
}
