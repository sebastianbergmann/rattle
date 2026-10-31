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

use function hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

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
}
