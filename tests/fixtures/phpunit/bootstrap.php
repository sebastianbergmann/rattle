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
    if ($class === 'SebastianBergmann\Rattle\Fixture\Phpunit\Classifier') {
        require __DIR__ . '/src/Classifier.php';
    }
});
