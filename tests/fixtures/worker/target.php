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
use SebastianBergmann\Rattle\Input;
use SebastianBergmann\Rattle\Target;

return Target::structured(
    'worker',
    static function (Input $input): void
    {
        $behaviour = $input->int(0, 16);
        $flag      = $input->bool();

        if ($behaviour === Behaviour::REJECT) {
            $input->reject();
        }

        (new Behaviour)->run($behaviour, $flag);
    },
)->expect(DomainException::class);
