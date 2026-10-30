<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\csv\IntegerColumn;
use SebastianBergmann\Rattle\Fixture\Targets\csv\InvalidIntegerException;
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'csv',
    static function (string $bytes): void
    {
        (new IntegerColumn)->convert($bytes);
    },
    64,
)->expect(InvalidIntegerException::class);
