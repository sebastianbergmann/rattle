<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\exit\CommandLine;
use SebastianBergmann\Rattle\Fixture\Targets\exit\UsageException;
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'exit',
    static function (string $bytes): void
    {
        (new CommandLine)->parse($bytes);
    },
    64,
)->expect(UsageException::class);
