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

use function array_count_values;
use function array_filter;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(BytesEncoding::class)]
#[Small]
final class BytesEncodingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<non-negative-int>, 2: non-negative-int}>
     */
    public static function replayedChoices(): array
    {
        return [
            'no choices'                            => ['', [], 10],
            'first choice that ends the string'     => ['', [0, 1, 2], 10],
            'two bytes'                             => ['ab', [1, 0x61, 1, 0x62, 0], 10],
            'more bytes than the maximum length'    => ['ab', [1, 0x61, 1, 0x62, 1, 0x63], 2],
            'choices that are too large or missing' => ["\xFF\x00", [7, 300, 1], 10],
        ];
    }

    public function testComputesTheLabelsOfStringsAndBytesAsPhpUnitsBytesGeneratorDoes(): void
    {
        $this->assertSame(hash('xxh3', 'bytes(0,4096)'), BytesEncoding::labelOfBytes(4096));
        $this->assertSame(hash('xxh3', 'element(' . hash('xxh3', 'bytes(0,4096)') . ')'), BytesEncoding::labelOfByte(4096));
    }

    public function testEncodesEachByteAsTheChoiceOneFollowedByItsValueAndEndsWithZero(): void
    {
        $this->assertSame([1, 0x61, 1, 0x00, 1, 0xFF, 0], BytesEncoding::choicesFor("a\x00\xFF", 10));
    }

    public function testEncodesTheEmptyString(): void
    {
        $this->assertSame([0], BytesEncoding::choicesFor('', 10));
    }

    public function testDoesNotEndAStringOfTheMaximumLengthWithZero(): void
    {
        $this->assertSame([1, 0x61, 1, 0x62], BytesEncoding::choicesFor('ab', 2));
    }

    public function testCutsOffAStringThatIsLongerThanTheMaximumLength(): void
    {
        $this->assertSame([1, 0x61, 1, 0x62], BytesEncoding::choicesFor('abc', 2));
    }

    /**
     * @param list<non-negative-int> $choices
     * @param non-negative-int       $maximumLength
     */
    #[DataProvider('replayedChoices')]
    public function testDecodesChoicesAsTheyAreReplayed(string $expected, array $choices, int $maximumLength): void
    {
        $this->assertSame($expected, BytesEncoding::bytesFor($choices, $maximumLength));
    }

    public function testDrawsAnotherByteWithAProbabilityOfEightyPercent(): void
    {
        $randomizer   = new Randomizer(new Xoshiro256StarStar(1));
        $distribution = BytesEncoding::anotherByte();
        $choices      = [];

        for ($i = 0; $i < 10000; $i++) {
            $choices[] = $distribution->sample($randomizer, 1);
        }

        $this->assertEqualsCanonicalizing([0, 1], array_values(array_unique($choices)));
        $this->assertEqualsWithDelta(BytesEncoding::PROBABILITY_OF_ANOTHER_BYTE, array_sum($choices) / 10000, 0.02);
    }

    public function testDrawsPrintableCharactersMostOftenAndTheBytes0x00And0xFFWithASignificantProbability(): void
    {
        $randomizer   = new Randomizer(new Xoshiro256StarStar(1));
        $distribution = BytesEncoding::byte();
        $bytes        = [];

        for ($i = 0; $i < 10000; $i++) {
            $bytes[] = $distribution->sample($randomizer, 0xFF);
        }

        $counts    = array_count_values($bytes);
        $printable = array_filter($bytes, static fn (int $byte): bool => $byte >= 0x20 && $byte <= 0x7E);

        $this->assertSame([], array_filter($bytes, static fn (int $byte): bool => $byte > 0xFF));

        // half of the bytes, and some of the ones that are drawn uniformly, are printable
        $this->assertEqualsWithDelta(0.5 + 0.3 * 95 / 256, count($printable) / 10000, 0.02);
        $this->assertEqualsWithDelta(0.1 + 0.3 / 256, ($counts[0x00] ?? 0) / 10000, 0.02);
        $this->assertEqualsWithDelta(0.1 + 0.3 / 256, ($counts[0xFF] ?? 0) / 10000, 0.02);
    }
}
