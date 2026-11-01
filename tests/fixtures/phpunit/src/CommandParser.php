<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Phpunit;

use LogicException;

/**
 * Parses commands. The command "SHUTDOWN" is not handled, which is the
 * defect that rattle finds with a token from the comparison.
 */
final class CommandParser
{
    public function parse(string $command): string
    {
        if ($command === 'SHUTDOWN') {
            throw new LogicException('cannot shut down');
        }

        return $command;
    }
}
