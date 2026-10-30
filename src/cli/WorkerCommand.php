<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle;

use const STDIN;
use const STDOUT;

/**
 * Runs a worker process, which is started by the supervisor. This command
 * is internal and not documented in --help.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class WorkerCommand
{
    /**
     * @param list<string> $arguments
     *
     * @return 0|2
     */
    public function run(array $arguments): int
    {
        $stream = new FrameStream(STDIN, STDOUT);

        try {
            $configuration = WorkerConfiguration::fromArguments($arguments);
        } catch (ArgumentsBuilderException $e) {
            $stream->write(['type' => 'error', 'message' => $e->getMessage()]);

            return 2;
        }

        return (new Worker($configuration, $stream))->run();
    }
}
