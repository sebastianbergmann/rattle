<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
// The project under test brings its own nikic/php-parser, here in the shape
// of version 4, which is not compatible with the version that rattle uses
\spl_autoload_register(static function (string $class): void
{
    $file = __DIR__ . '/src/' . \str_replace('\\', '/', $class) . '.php';

    if (\is_file($file)) {
        require $file;
    }
});
