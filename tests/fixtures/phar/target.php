<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use PhpParser\ParserFactory;
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'php-parser-4',
    static function (string $bytes): void
    {
        (new ParserFactory)->create(ParserFactory::PREFER_PHP7)->parse($bytes);
    },
    16,
);
