<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Targets\memory\DecodeException;
use SebastianBergmann\Rattle\Fixture\Targets\memory\RunLengthDecoder;
use SebastianBergmann\Rattle\Target;

// fuzz this target with a memory limit, for instance --memory-limit 64M
return Target::bytes(
    'memory',
    static function (string $bytes): void
    {
        (new RunLengthDecoder)->decode($bytes);
    },
    20,
)->expect(DecodeException::class);
