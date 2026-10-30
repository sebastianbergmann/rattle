<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\csv;

use function ctype_digit;
use function sprintf;
use function strlen;
use function substr;

/**
 * Converts the value of an integer column of a CSV file, modelled on the
 * defect in sebastian/csv-parser that is described in "Security through
 * chaos" (phpunit.expert, 2026-04-08): the conversion goes through float,
 * which triggers a warning for a digit string that is too long to be
 * represented as an int.
 */
final class IntegerColumn
{
    public function convert(string $value): int
    {
        $sign   = 1;
        $digits = $value;

        if ($value !== '' && $value[0] === '-') {
            $sign   = -1;
            $digits = substr($value, 1);
        }

        if ($digits === '') {
            throw new InvalidIntegerException('The value is empty');
        }

        for ($i = 0; $i < strlen($digits); $i++) {
            if (!ctype_digit($digits[$i])) {
                throw new InvalidIntegerException(sprintf('"%s" is not an integer', $value));
            }
        }

        return $sign * (int) (float) $digits;
    }
}
