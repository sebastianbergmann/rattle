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

use function count;
use function min;
use Closure;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\SpanKind;
use SebastianBergmann\ChoiceSequence\UniformDistribution;

/**
 * Creates choice sequences with spans and maxima for tests, as Input draws
 * them.
 */
final readonly class Sequences
{
    /**
     * @param non-negative-int $maximumLength
     */
    public static function ofBytes(string $bytes, int $maximumLength = 100): ChoiceSequence
    {
        return self::drawn(
            BytesEncoding::choicesFor($bytes, $maximumLength),
            static function (Input $input) use ($maximumLength): void
            {
                $input->bytes($maximumLength);
            },
        );
    }

    /**
     * Creates the choice sequence of a list of integers in [0, 9] as PHPUnit's
     * generators draw it: each element is a span of the kind Element that
     * consists of the choice 1 followed by the span of the integer, and the
     * choice 0 that ends the list is a span of the kind Element as well.
     *
     * @param list<int<0, 9>> $values
     */
    public static function ofList(array $values): ChoiceSequence
    {
        $choices = [];

        foreach ($values as $value) {
            $choices[] = 1;
            $choices[] = $value;
        }

        $choices[] = 0;

        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $source->startSpan('list');

        for ($i = 0; $i < count($values); $i++) {
            $source->startSpan('element', SpanKind::Element);
            $source->draw(1, new UniformDistribution);
            $source->startSpan('integer');
            $source->draw(9, new UniformDistribution);
            $source->endSpan();
            $source->endSpan();
        }

        $source->startSpan('element', SpanKind::Element);
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->endSpan();

        return $source->sequence();
    }

    /**
     * Returns the list of integers that the choices are replayed as (see
     * ofList()).
     *
     * @param list<non-negative-int> $choices
     *
     * @return list<int>
     */
    public static function listFor(array $choices): array
    {
        $values = [];
        $index  = 0;

        while (min($choices[$index] ?? 0, 1) === 1) {
            $values[] = min($choices[$index + 1] ?? 0, 9);
            $index += 2;
        }

        return $values;
    }

    /**
     * @param list<non-negative-int> $choices
     * @param Closure(Input): void   $draw
     */
    public static function drawn(array $choices, Closure $draw): ChoiceSequence
    {
        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $draw(new Input($source, false));

        return $source->sequence();
    }
}
