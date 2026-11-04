<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Fixture\Worker\Behaviour;
use SebastianBergmann\Rattle\Target;

// the code of Behaviour is instrumented while the worker starts, so the
// constant operands of its comparisons are found before any input
$behaviour = new Behaviour;

return Target::bytes(
    'bytes',
    static function (string $bytes) use ($behaviour): void
    {
        if ($behaviour->passed($bytes)) {
            throw new LogicException('passed');
        }
    },
    16,
);
