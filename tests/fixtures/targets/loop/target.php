<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\loop\SyntaxError;
use SebastianBergmann\Rattle\Fixture\Targets\loop\Tokenizer;
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'endless-loop',
    static function (string $bytes): void
    {
        (new Tokenizer)->tokenize($bytes);
    },
    64,
)->expect(SyntaxError::class);
