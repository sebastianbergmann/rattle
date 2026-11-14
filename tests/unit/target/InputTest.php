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

use const PHP_INT_MAX;
use const PHP_INT_MIN;
use function strlen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\Bytes;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\Label;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

#[CoversClass(Input::class)]
#[CoversClass(InputRejectedException::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(ValueExporter::class)]
#[Small]
final class InputTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: list<non-negative-int>, 2: int, 3: int}>
     */
    public static function integers(): array
    {
        return [
            'zero is the simplest integer of a range that starts at zero' => [0, [], 0, 10],
            'the minimum is the simplest integer of a positive range'     => [5, [], 5, 10],
            'offset from the minimum of a positive range'                 => [7, [2], 5, 10],
            'offset beyond the maximum of a positive range'               => [10, [99], 5, 10],
            'the maximum is the simplest integer of a negative range'     => [-5, [], -10, -5],
            'offset from the maximum of a negative range'                 => [-7, [2], -10, -5],
            'zero is the simplest integer of a range around zero'         => [0, [], -10, 10],
            'positive integer of a range around zero'                     => [3, [0, 3], -10, 10],
            'negative integer that is closest to zero'                    => [-1, [1, 0], -10, 10],
            'negative integer of a range around zero'                     => [-4, [1, 3], -10, 10],
            'negative integer beyond the minimum of a range around zero'  => [-10, [1, 99], -10, 10],
            'largest integer'                                             => [PHP_INT_MAX, [0, PHP_INT_MAX], PHP_INT_MIN, PHP_INT_MAX],
            'smallest integer'                                            => [PHP_INT_MIN, [1, PHP_INT_MAX], PHP_INT_MIN, PHP_INT_MAX],
        ];
    }

    /**
     * @return array<string, array{0: bool, 1: list<non-negative-int>}>
     */
    public static function booleans(): array
    {
        return [
            'false is the simplest boolean' => [false, []],
            'true'                          => [true, [1]],
            'choice that is too large'      => [true, [7]],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<non-negative-int>}>
     */
    public static function elements(): array
    {
        return [
            'the first element is the simplest' => ['a', []],
            'second element'                    => ['b', [1]],
            'choice that is too large'          => ['c', [9]],
        ];
    }

    public function testReplaysTheChoicesOfARawStringAsThatString(): void
    {
        $source = $this->replay(BytesEncoding::choicesFor("GIF8\x00\xFF", 100));

        $this->assertSame("GIF8\x00\xFF", new Input($source, false)->bytes(100));
        $this->assertSame(BytesEncoding::choicesFor("GIF8\x00\xFF", 100), $source->sequence()->choices());
    }

    public function testMarksTheStringAndEachOfItsBytesAsSpans(): void
    {
        $source = $this->replay([1, 0x61, 1, 0x62, 0]);

        new Input($source, false)->bytes(10);

        $sequence = $source->sequence();

        $this->assertEquals(
            [
                new Span(0, 5, new Bytes(0, 10)->label(), null, SpanKind::Bytes),
                new Span(0, 2, Label::of('element', new Bytes(0, 10)->label()), 0, SpanKind::Element),
                new Span(2, 4, Label::of('element', new Bytes(0, 10)->label()), 0, SpanKind::Element),
                new Span(4, 5, Label::of('element', new Bytes(0, 10)->label()), 0, SpanKind::Element),
            ],
            $sequence->spans(),
        );

        $this->assertSame(1, $sequence->maximumAt(0));
        $this->assertSame(0xFF, $sequence->maximumAt(1));
        $this->assertSame(1, $sequence->maximumAt(4));
    }

    public function testDoesNotDrawAFinalChoiceWhenTheMaximumLengthIsReached(): void
    {
        $source = $this->replay([1, 0x61, 1, 0x62, 1, 0x63]);

        $this->assertSame('ab', new Input($source, false)->bytes(2));
        $this->assertSame([1, 0x61, 1, 0x62], $source->sequence()->choices());
    }

    public function testDrawsTheEmptyStringFromNoChoices(): void
    {
        $source = $this->replay([]);

        $this->assertSame('', new Input($source, false)->bytes(10));
        $this->assertSame([0], $source->sequence()->choices());
    }

    public function testClampsChoicesThatExceedTheirRange(): void
    {
        $this->assertSame("\xFF", new Input($this->replay([5, 300, 0]), false)->bytes(10));
    }

    public function testRandomlyDrawnBytesAreReplayedAsTheSameBytes(): void
    {
        for ($seed = 0; $seed < 20; $seed++) {
            $random = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar($seed)));
            $bytes  = new Input($random, false)->bytes(64);

            $this->assertLessThanOrEqual(64, strlen($bytes));
            $this->assertSame($bytes, new Input(ChoiceSource::replay($random->sequence()), false)->bytes(64));
            $this->assertSame(BytesEncoding::choicesFor($bytes, 64), $random->sequence()->choices());
        }
    }

    public function testRejectsANegativeMaximumLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Input($this->replay([]), false)->bytes(-1);
    }

    /**
     * @param list<non-negative-int> $choices
     */
    #[DataProvider('integers')]
    public function testDrawsIntegersAsPhpUnitsIntegerGenerator(int $expected, array $choices, int $min, int $max): void
    {
        $this->assertSame($expected, new Input($this->replay($choices), false)->int($min, $max));
    }

    public function testMarksAnIntegerAsASpan(): void
    {
        $source = $this->replay([1, 3]);

        new Input($source, false)->int(-10, 10);

        $this->assertEquals(
            [new Span(0, 2, Label::of('integers', '-10', '10'), null)],
            $source->sequence()->spans(),
        );
    }

    public function testRejectsAnEmptyRangeOfIntegers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Input($this->replay([]), false)->int(1, 0);
    }

    public function testRandomlyDrawnIntegersAreReplayedAsTheSameIntegers(): void
    {
        for ($seed = 0; $seed < 50; $seed++) {
            $random = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar($seed)));
            $value  = new Input($random, false)->int(PHP_INT_MIN, PHP_INT_MAX);

            $this->assertSame($value, new Input(ChoiceSource::replay($random->sequence()), false)->int(PHP_INT_MIN, PHP_INT_MAX));
        }
    }

    /**
     * @param list<non-negative-int> $choices
     */
    #[DataProvider('booleans')]
    public function testDrawsBooleans(bool $expected, array $choices): void
    {
        $source = $this->replay($choices);

        $this->assertSame($expected, new Input($source, false)->bool());
        $this->assertSame(1, $source->sequence()->maximumAt(0));
    }

    /**
     * @param list<non-negative-int> $choices
     */
    #[DataProvider('elements')]
    public function testPicksAnElementWhoseFirstElementIsTheSimplest(string $expected, array $choices): void
    {
        $this->assertSame($expected, new Input($this->replay($choices), false)->pick(['x' => 'a', 'y' => 'b', 'z' => 'c']));
    }

    public function testCannotPickAnElementOfAnEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Input($this->replay([]), false)->pick([]);
    }

    public function testRejectsTheInput(): void
    {
        $input = new Input($this->replay([]), false);

        $this->assertFalse($input->isRejected());

        try {
            $input->reject();
        } catch (InputRejectedException) {
        }

        $this->assertTrue($input->isRejected());
    }

    public function testRejectsAnInputThatNeedsTooManyChoices(): void
    {
        $input = new Input(ChoiceSource::replay(new ChoiceSequence([1, 1, 1, 1, 1, 1]), 3), false);

        try {
            $input->bytes(100);

            $this->fail();
        } catch (InputRejectedException $e) {
            $this->assertSame('The input was rejected because it needs more than 3 choices', $e->getMessage());
        }

        $this->assertTrue($input->isRejected());
    }

    public function testRecordsTheDrawnValuesWhenAskedTo(): void
    {
        $input = new Input($this->replay([1, 0x61, 0, 1, 1, 2]), true);

        $input->bytes(10);
        $input->bool();
        $input->int(0, 10);
        $input->pick([1, 2, 3]);

        $this->assertSame(["'a'", 'true', '1', '3'], $input->drawnValues());
    }

    public function testDoesNotRecordTheDrawnValuesUnlessAskedTo(): void
    {
        $input = new Input($this->replay([1, 0x61, 0]), false);

        $input->bytes(10);

        $this->assertSame([], $input->drawnValues());
    }

    /**
     * @param list<non-negative-int> $choices
     */
    private function replay(array $choices): ChoiceSource
    {
        return ChoiceSource::replay(new ChoiceSequence($choices));
    }
}
