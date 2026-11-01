<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\command\Thumbnailer;
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'thumbnailer',
    static function (string $bytes): void
    {
        (new Thumbnailer)->create($bytes);
    },
    128,
);
