<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\memory;

use function str_repeat;
use function strlen;
use function substr;
use function unpack;

/**
 * Decodes runs of a character, each encoded as a count of four bytes in
 * network byte order followed by the character. The count is trusted, so a
 * large count exhausts the memory.
 */
final class RunLengthDecoder
{
    public function decode(string $encoded): string
    {
        if (strlen($encoded) % 5 !== 0) {
            throw new DecodeException('Incomplete run');
        }

        $decoded = '';

        for ($offset = 0; $offset < strlen($encoded); $offset += 5) {
            $count = unpack('N', substr($encoded, $offset, 4))[1];

            $decoded .= str_repeat($encoded[$offset + 4], $count);
        }

        return $decoded;
    }
}
