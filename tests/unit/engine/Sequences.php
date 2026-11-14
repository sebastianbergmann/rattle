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

use function assert;
use function count;
use function mb_ord;
use function mb_str_split;
use function min;
use function ord;
use function strlen;
use Closure;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\ChoiceSequence\Repetition;
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
     * Creates the choice sequence of a binary string with a minimum length as
     * PHPUnit's generators draw it: the string is a span of the kind Bytes,
     * the bytes that the minimum length requires are spans of the kind
     * RequiredElement that hold only their value, every other byte is a span
     * of the kind Element that consists of the choice 1 followed by its
     * value, and the choice 0 that ends the string is a span of the kind
     * Element as well, unless the string has the maximum length.
     *
     * @param non-negative-int  $minimumLength
     * @param ?non-negative-int $maximumLength
     */
    public static function ofBytesWithMinimumLength(string $bytes, int $minimumLength, ?int $maximumLength = null): ChoiceSequence
    {
        $choices = [];

        for ($i = 0; $i < strlen($bytes); $i++) {
            if ($i >= $minimumLength) {
                $choices[] = 1;
            }

            $choices[] = ord($bytes[$i]);
        }

        if ($maximumLength === null || strlen($bytes) < $maximumLength) {
            $choices[] = 0;
        }

        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $source->startSpan('bytes', SpanKind::Bytes);

        for ($size = 0; $maximumLength === null || $size < $maximumLength; $size++) {
            if ($size < $minimumLength) {
                $source->startSpan('byte', SpanKind::RequiredElement);
            } else {
                $source->startSpan('byte', SpanKind::Element);
            }

            if ($size >= $minimumLength && $source->draw(1, new UniformDistribution) === 0) {
                $source->endSpan();

                break;
            }

            $source->draw(0xFF, new UniformDistribution);
            $source->endSpan();
        }

        $source->endSpan();

        return $source->sequence();
    }

    /**
     * Creates the choice sequence of a string of Unicode characters as
     * PHPUnit's generators draw it: the string is a span of the kind
     * Characters, the characters that the minimum length requires are spans
     * of the kind RequiredElement that hold only the index of their code
     * point in the order of CodePoints, every other character is a span of
     * the kind Element that consists of the choice 1 followed by that index,
     * and the choice 0 that ends the string is a span of the kind Element as
     * well.
     *
     * @param non-negative-int $minimumLength
     */
    public static function ofCharacters(string $string, int $minimumLength = 0): ChoiceSequence
    {
        $choices = [];

        foreach (mb_str_split($string, 1, 'UTF-8') as $index => $character) {
            $codePoint = mb_ord($character, 'UTF-8');

            assert($codePoint !== false && $codePoint >= 0 && $codePoint <= 0x10FFFF);

            if ($index >= $minimumLength) {
                $choices[] = 1;
            }

            $choices[] = CodePoints::index($codePoint);
        }

        $choices[] = 0;

        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $source->startSpan('string', SpanKind::Characters);

        new Repetition('character', $minimumLength, null)->repeat(
            $source,
            static function () use ($source): bool
            {
                $source->draw(CodePoints::LARGEST_INDEX, new UniformDistribution);

                return true;
            },
        );

        $source->endSpan();

        return $source->sequence();
    }

    /**
     * Creates the choice sequence of a list of booleans with a minimum size
     * as PHPUnit's generators draw it: each element that the minimum size
     * requires is a span of the kind RequiredElement that consists of the
     * span of the boolean, every other element is a span of the kind Element
     * that consists of the choice 1 followed by the span of the boolean, and
     * the choice 0 that ends the list is a span of the kind Element as well,
     * unless the list has the maximum size. The choice of a boolean has the
     * maximum 1, as the choice that decides whether there is another element
     * has.
     *
     * @param list<bool>        $values
     * @param non-negative-int  $minimumSize
     * @param ?non-negative-int $maximumSize
     */
    public static function ofBooleans(array $values, int $minimumSize, ?int $maximumSize = null): ChoiceSequence
    {
        $choices = [];

        foreach ($values as $index => $value) {
            if ($index >= $minimumSize) {
                $choices[] = 1;
            }

            if ($value) {
                $choices[] = 1;
            } else {
                $choices[] = 0;
            }
        }

        if ($maximumSize === null || count($values) < $maximumSize) {
            $choices[] = 0;
        }

        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $source->startSpan('list');

        new Repetition('element', $minimumSize, $maximumSize)->repeat(
            $source,
            static function () use ($source): bool
            {
                $source->startSpan('boolean');
                $source->draw(1, new UniformDistribution);
                $source->endSpan();

                return true;
            },
        );

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
     * Returns the list of booleans that the choices are replayed as (see
     * ofBooleans()).
     *
     * @param list<non-negative-int> $choices
     * @param non-negative-int       $minimumSize
     *
     * @return list<bool>
     */
    public static function booleansFor(array $choices, int $minimumSize): array
    {
        $values = [];
        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        new Repetition('element', $minimumSize, null)->repeat(
            $source,
            static function () use ($source, &$values): bool
            {
                $values[] = $source->draw(1, new UniformDistribution) === 1;

                return true;
            },
        );

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
