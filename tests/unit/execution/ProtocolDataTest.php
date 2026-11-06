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

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolData::class)]
#[CoversClass(ProtocolException::class)]
#[Small]
final class ProtocolDataTest extends TestCase
{
    /**
     * @return array<string, array{0: Closure(mixed): mixed, 1: mixed, 2: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'list of non-negative integers that is not an array'    => [ProtocolData::listOfNonNegativeIntegers(...), 1, 'Expected a list of non-negative integers'],
            'list of non-negative integers that is not a list'      => [ProtocolData::listOfNonNegativeIntegers(...), [1 => 1], 'Expected a list of non-negative integers'],
            'list of non-negative integers with a negative integer' => [ProtocolData::listOfNonNegativeIntegers(...), [1, -1], 'Expected a list of non-negative integers'],
            'list of non-negative integers with a string'           => [ProtocolData::listOfNonNegativeIntegers(...), ['1'], 'Expected a list of non-negative integers'],
            'list of integers that is not an array'                 => [ProtocolData::listOfIntegers(...), 1, 'Expected a list of integers'],
            'list of integers that is not a list'                   => [ProtocolData::listOfIntegers(...), ['a' => 1], 'Expected a list of integers'],
            'list of integers with a string'                        => [ProtocolData::listOfIntegers(...), [1, '2'], 'Expected a list of integers'],
            'list of strings that is not an array'                  => [ProtocolData::listOfStrings(...), 'a', 'Expected a list of strings'],
            'list of strings that is not a list'                    => [ProtocolData::listOfStrings(...), [1 => 'a'], 'Expected a list of strings'],
            'list of strings with an integer'                       => [ProtocolData::listOfStrings(...), ['a', 1], 'Expected a list of strings'],
            'string that is an integer'                             => [ProtocolData::string(...), 1, 'Expected a string'],
            'non-empty string that is empty'                        => [ProtocolData::nonEmptyString(...), '', 'Expected a non-empty string'],
            'non-empty string that is null'                         => [ProtocolData::nonEmptyString(...), null, 'Expected a non-empty string'],
            'array that is a string'                                => [ProtocolData::array(...), 'a', 'Expected an array'],
        ];
    }

    public function testReadsValuesOfTheExpectedTypes(): void
    {
        $this->assertSame([0, 1], ProtocolData::listOfNonNegativeIntegers([0, 1]));
        $this->assertSame([-1, 1], ProtocolData::listOfIntegers([-1, 1]));
        $this->assertSame(['', 'a'], ProtocolData::listOfStrings(['', 'a']));
        $this->assertSame('', ProtocolData::string(''));
        $this->assertSame('a', ProtocolData::nonEmptyString('a'));
        $this->assertSame(['a' => 1], ProtocolData::array(['a' => 1]));
    }

    /**
     * @param Closure(mixed): mixed $read
     */
    #[DataProvider('invalidValues')]
    public function testRejectsValuesOfOtherTypes(Closure $read, mixed $value, string $message): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $read($value);
    }
}
