<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\phpversion;

use const PHP_MINOR_VERSION;
use function strlen;
use UnexpectedValueException;

/**
 * Rejects every string of two or more bytes on PHP 8.6 and later, but not on
 * PHP 8.5, so that comparing the outcomes of its inputs on real binaries of
 * these versions finds a difference.
 */
final class MinorVersion
{
    public function parse(string $bytes): int
    {
        if (strlen($bytes) < 2) {
            return 0;
        }

        if (PHP_MINOR_VERSION > 5) {
            throw new UnexpectedValueException('Strings are not parsed on this version of PHP');
        }

        return strlen($bytes);
    }
}
