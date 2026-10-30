<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\loop;

use function ctype_alpha;
use function strlen;
use function strpos;
use function substr;

/**
 * Splits a string into words and quoted strings. A backslash escapes the
 * character that follows it, but a backslash at the end of the input is
 * never consumed, so that the tokenizer loops forever.
 */
final class Tokenizer
{
    /**
     * @return list<string>
     */
    public function tokenize(string $input): array
    {
        $tokens = [];
        $word   = '';
        $i      = 0;
        $length = strlen($input);

        while ($i < $length) {
            $character = $input[$i];

            if ($character === '"') {
                $end = strpos($input, '"', $i + 1);

                if ($end === false) {
                    throw new SyntaxError('Unterminated string');
                }

                $tokens[] = substr($input, $i + 1, $end - $i - 1);
                $i        = $end + 1;

                continue;
            }

            if ($character === '\\') {
                $i += strlen(substr($input, $i + 1, 1));

                continue;
            }

            if (ctype_alpha($character)) {
                $word .= $character;
            } elseif ($word !== '') {
                $tokens[] = $word;
                $word     = '';
            }

            $i++;
        }

        if ($word !== '') {
            $tokens[] = $word;
        }

        return $tokens;
    }
}
