<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Target;

// fails for an input as often as the value of its first byte tells, counted
// from the first input that the process executes, so that an input does not
// fail in the same way again when it is replayed often enough
return Target::bytes(
    'flaky',
    static function (string $bytes): void
    {
        static $executions = 0;

        if ($bytes !== '' && $executions++ < \ord($bytes[0])) {
            throw new LogicException('fails for now');
        }
    },
    16,
);
