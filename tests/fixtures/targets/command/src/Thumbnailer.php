<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\command;

use function array_filter;
use function explode;
use function getenv;
use function implode;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;

/**
 * Creates thumbnails with a command line into which it puts the name of the
 * file without escaping it.
 *
 * So that the commands of random inputs cannot do harm, a restricted shell,
 * which cannot redirect output, runs them in the temporary directory, and only
 * finds the commands in the directories of the PATH of rattle's sentinel
 * command.
 */
final class Thumbnailer
{
    public function create(string $file): string
    {
        if (str_contains($file, "\0")) {
            return '';
        }

        $path = array_filter(
            explode(':', (string) getenv('PATH')),
            static fn (string $directory): bool => str_contains($directory, 'rattle-sentinel'),
        );

        $process = proc_open(
            ['/bin/bash', '-r', '-c', 'echo convert ' . $file . ' thumbnail.png'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            ['PATH' => implode(':', $path)],
        );

        if ($process === false) {
            return '';
        }

        $output = (string) stream_get_contents($pipes[1]);

        stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }
}
