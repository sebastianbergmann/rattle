<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\evaluation\Calculator;
use SebastianBergmann\Rattle\Target;

// an expression that is not valid code, or that uses a constant or a function
// that does not exist, is an error that the calculator documents
return Target::bytes(
    'calculator',
    static function (string $bytes): void
    {
        (new Calculator)->calculate($bytes);
    },
    128,
)->expect(Error::class);
