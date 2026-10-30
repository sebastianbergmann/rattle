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
use function chr;
use function ctype_alnum;
use function ctype_digit;
use function ctype_xdigit;
use function explode;
use function file_get_contents;
use function hexdec;
use function is_file;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Parses a dictionary file: one token per line, in double quotes, optionally
 * preceded by a name of letters, digits, and underscores, an optional level
 * such as "@1", and an equals sign. Within the quotes, \\ stands for a
 * backslash, \" for a double quote, and \xNN for the byte with the
 * hexadecimal value NN. Empty lines are ignored, and so is what follows a #
 * at the start of a line or after a token.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DictionaryParser
{
    /**
     * @throws InvalidDictionaryException
     *
     * @return list<non-empty-string>
     */
    public function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidDictionaryException(sprintf('The dictionary "%s" does not exist', $path));
        }

        return $this->parse((string) file_get_contents($path));
    }

    /**
     * @throws InvalidDictionaryException
     *
     * @return list<non-empty-string>
     */
    public function parse(string $dictionary): array
    {
        $tokens = [];

        foreach (explode("\n", $dictionary) as $index => $line) {
            $token = $this->tokenIn(trim($line), $index + 1);

            if ($token !== null && $token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Returns the token on the line, or null for a line without a token.
     *
     * @throws InvalidDictionaryException
     */
    private function tokenIn(string $line, int $number): ?string
    {
        if ($line === '' || $line[0] === '#') {
            return null;
        }

        $position = $this->skipName($line);

        if (($line[$position] ?? '') !== '"') {
            throw $this->invalid($number);
        }

        $token = '';

        for ($position++; $position < strlen($line); $position++) {
            $character = $line[$position];

            if ($character === '"') {
                $rest = trim(substr($line, $position + 1));

                if ($rest !== '' && $rest[0] !== '#') {
                    throw $this->invalid($number);
                }

                return $token;
            }

            if ($character !== '\\') {
                $token .= $character;

                continue;
            }

            $escaped = $line[++$position] ?? '';

            if ($escaped === '\\' || $escaped === '"') {
                $token .= $escaped;

                continue;
            }

            $hex = substr($line, $position + 1, 2);

            if ($escaped !== 'x' || strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                throw $this->invalid($number);
            }

            $byte = (int) hexdec($hex);

            assert($byte >= 0 && $byte <= 0xFF);

            $token .= chr($byte);
            $position += 2;
        }

        // the closing quote is missing
        throw $this->invalid($number);
    }

    /**
     * Returns the position after the optional name, level, and equals sign at
     * the start of the line.
     */
    private function skipName(string $line): int
    {
        $position = 0;

        while ($position < strlen($line) && (ctype_alnum($line[$position]) || $line[$position] === '_')) {
            $position++;
        }

        if ($position > 0 && ($line[$position] ?? '') === '@') {
            $position++;

            while ($position < strlen($line) && ctype_digit($line[$position])) {
                $position++;
            }
        }

        if ($position > 0 && ($line[$position] ?? '') === '=') {
            return $position + 1;
        }

        return 0;
    }

    private function invalid(int $number): InvalidDictionaryException
    {
        return new InvalidDictionaryException(sprintf('Line %d of the dictionary is not valid', $number));
    }
}
