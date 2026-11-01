<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\versions;

use function strlen;
use LengthException;

/**
 * Shows the length of a string divided by three. A float becomes a string
 * with the precision of PHP's ini setting precision, so that the ratio is
 * shown with fewer digits, and is rejected, when the precision is lower,
 * which the fixtures of differential fuzzing take for another version of PHP.
 */
final class Ratio
{
    public function show(string $bytes): string
    {
        if (strlen($bytes) % 3 === 0) {
            return (string) (strlen($bytes) / 3);
        }

        $shown = (string) (strlen($bytes) / 3);

        if (strlen($shown) < 8) {
            throw new LengthException('The ratio is shown with too few digits: ' . $shown);
        }

        return $shown;
    }
}
