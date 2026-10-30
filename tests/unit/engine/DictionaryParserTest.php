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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DictionaryParser::class)]
#[UsesClass(InvalidDictionaryException::class)]
#[Small]
final class DictionaryParserTest extends TestCase
{
    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function invalidLines(): array
    {
        return [
            'unterminated string'                             => ['"unterminated'],
            'text after the string'                           => ['"a" trailing'],
            'unknown escape sequence'                         => ['"\\q"'],
            'hexadecimal escape sequence with invalid digits' => ['"\\xZZ"'],
            'hexadecimal escape sequence with one digit'      => ['"\\x1"'],
            'name without an equals sign'                     => ['name "a"'],
            'equals sign without a name'                      => ['=\"a\"'],
        ];
    }

    public function testParsesTheFormatOfDictionaryFiles(): void
    {
        $this->assertSame(
            ['RATL', "\x00\xFF", 'quote"backslash\\', 'trailing'],
            (new DictionaryParser)->parseFile(__DIR__ . '/../../fixtures/engine/dictionary.txt'),
        );
    }

    public function testParsesNamesWithLevels(): void
    {
        $this->assertSame(['a', 'b', 'c'], (new DictionaryParser)->parse("kw@1=\"a\"\n_k2=\"b\"\n\"c\"   # comment"));
    }

    /**
     * @param non-empty-string $line
     */
    #[DataProvider('invalidLines')]
    public function testRejectsInvalidLines(string $line): void
    {
        $this->expectException(InvalidDictionaryException::class);
        $this->expectExceptionMessage('Line 1 of the dictionary is not valid');

        (new DictionaryParser)->parse($line);
    }

    public function testIgnoresEmptyTokens(): void
    {
        $this->assertSame([], (new DictionaryParser)->parse('""'));
    }

    public function testRejectsAnInvalidLine(): void
    {
        $this->expectException(InvalidDictionaryException::class);
        $this->expectExceptionMessage('Line 2 of the dictionary is not valid');

        (new DictionaryParser)->parseFile(__DIR__ . '/../../fixtures/engine/invalid-dictionary.txt');
    }

    public function testRejectsADictionaryThatDoesNotExist(): void
    {
        $this->expectException(InvalidDictionaryException::class);

        (new DictionaryParser)->parseFile('/does/not/exist');
    }
}
