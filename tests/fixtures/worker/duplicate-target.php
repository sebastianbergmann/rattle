<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use SebastianBergmann\Rattle\Input;
use SebastianBergmann\Rattle\Target;

// returns a target with the name of the target of target.php
return Target::structured(
    'worker',
    static function (Input $input): void
    {
        $input->bool();
    },
);
