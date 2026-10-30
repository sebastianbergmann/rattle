<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
\spl_autoload_register(static function (string $class): void
{
    $prefix = 'SebastianBergmann\\Rattle\\Fixture\\Targets\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    [$directory, $name] = \explode('\\', \substr($class, \strlen($prefix)), 2);

    $file = __DIR__ . '/' . $directory . '/src/' . $name . '.php';

    if (\is_file($file)) {
        require $file;
    }
});
