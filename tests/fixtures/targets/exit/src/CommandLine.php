<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\exit;

use function explode;
use function strlen;

/**
 * Parses command-line arguments. The option -h prints a usage message and
 * exits, which ends the process that parses the arguments.
 */
final class CommandLine
{
    /**
     * @return array{options: list<string>, arguments: list<string>}
     */
    public function parse(string $commandLine): array
    {
        $options   = [];
        $arguments = [];

        foreach (explode(' ', $commandLine) as $argument) {
            if ($argument === '') {
                continue;
            }

            if ($argument[0] !== '-') {
                $arguments[] = $argument;

                continue;
            }

            if (strlen($argument) < 2) {
                throw new UsageException('Missing option');
            }

            if ($argument[1] === 'h') {
                print 'Usage: command [-h] [-v] <arguments>';

                exit(0);
            }

            $options[] = $argument[1];
        }

        return ['options' => $options, 'arguments' => $arguments];
    }
}
