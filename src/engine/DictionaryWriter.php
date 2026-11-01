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

use function ord;
use function sprintf;
use function strlen;

/**
 * Writes tokens as a dictionary in the format that DictionaryParser reads:
 * one token per line, in double quotes, with a backslash and a double quote
 * escaped, and every byte that is not printable ASCII written as \xNN.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DictionaryWriter
{
    /**
     * @param non-empty-string       $comment written as a comment before the tokens
     * @param list<non-empty-string> $tokens
     */
    public function write(string $comment, array $tokens): string
    {
        $dictionary = '# ' . $comment . "\n";

        foreach ($tokens as $token) {
            $dictionary .= '"' . $this->escape($token) . '"' . "\n";
        }

        return $dictionary;
    }

    private function escape(string $token): string
    {
        $escaped = '';

        for ($i = 0; $i < strlen($token); $i++) {
            $byte = ord($token[$i]);

            if ($token[$i] === '\\' || $token[$i] === '"') {
                $escaped .= '\\' . $token[$i];
            } elseif ($byte < 0x20 || $byte > 0x7E) {
                $escaped .= sprintf('\\x%02X', $byte);
            } else {
                $escaped .= $token[$i];
            }
        }

        return $escaped;
    }
}
