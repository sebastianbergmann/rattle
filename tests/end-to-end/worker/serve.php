<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\EndToEnd\Worker;

use const PHP_EOL;
use function count;
use function fopen;
use function fwrite;
use function implode;
use function is_array;
use function is_string;
use function ob_end_flush;
use function ob_get_level;
use function print_r;
use function register_shutdown_function;
use function rewind;
use function sprintf;
use SebastianBergmann\Rattle\Frame;
use SebastianBergmann\Rattle\FrameStream;
use SebastianBergmann\Rattle\ProtocolException;
use SebastianBergmann\Rattle\Worker;
use SebastianBergmann\Rattle\WorkerConfiguration;

/**
 * Runs a worker in this process, which reads the given messages, as frames,
 * from a stream instead of from its standard input stream, and prints what
 * it writes as frames to another stream instead of to its standard output
 * stream, also when the process ends while the worker executes an input.
 * A worker can only be run once in a process.
 *
 * @param list<array<string, mixed>> $messages
 */
function serve(WorkerConfiguration $configuration, array $messages): void
{
    $input  = fopen('php://memory', 'w+');
    $output = fopen('php://memory', 'w+');

    if ($input === false || $output === false) {
        return;
    }

    foreach ($messages as $message) {
        fwrite($input, Frame::encode($message));
    }

    rewind($input);

    $level = ob_get_level();

    // the frames are printed after the shutdown function of the worker,
    // which reports the input that was executed when the process ended
    register_shutdown_function(
        static function () use ($output, $level): void
        {
            register_shutdown_function(
                static function () use ($output, $level): void
                {
                    // the worker redirects the output of the target to its
                    // standard error stream
                    while (ob_get_level() > $level) {
                        ob_end_flush();
                    }

                    rewind($output);

                    $frames = new FrameStream($output, $output);

                    try {
                        while (($frame = $frames->read()) !== null) {
                            print describe($frame) . PHP_EOL;
                        }
                    } catch (ProtocolException $e) {
                        print $e->getMessage() . PHP_EOL;
                    }
                },
            );
        },
    );

    $exit = (new Worker($configuration, new FrameStream($input, $output)))->run();

    while (ob_get_level() > $level) {
        ob_end_flush();
    }

    print 'Exit: ' . $exit . PHP_EOL;
}

/**
 * @param array<mixed> $frame
 */
function describe(array $frame): string
{
    $type = is_string($frame['type'] ?? null) ? $frame['type'] : '';

    if ($type === 'result' && is_array($frame['result'] ?? null)) {
        $result      = $frame['result'];
        $description = sprintf('result %s: %s', print_r($result['id'] ?? null, true), print_r($result['outcome'] ?? null, true));

        if (is_array($result['failure'] ?? null)) {
            $failure = $result['failure'];

            $description .= sprintf(
                ' (%s)',
                match (true) {
                    isset($failure['description']) => print_r($failure['description'], true),
                    isset($failure['class'])       => print_r($failure['class'], true) . ' at ' . print_r($failure['location'] ?? null, true),
                    default                        => print_r($failure['kind'] ?? null, true),
                },
            );
        }

        if (is_array($result['tokens'] ?? null) && $result['tokens'] !== []) {
            $description .= sprintf(', %d tokens', count($result['tokens']));
        }

        if (is_array($result['blockMaps'] ?? null)) {
            $files = [];

            foreach ($result['blockMaps'] as $map) {
                $files[] = is_array($map) && is_array($map['blocks'] ?? null)
                    ? sprintf('%s (%s lines, %d blocks)', print_r($map['file'] ?? null, true), print_r($map['lines'] ?? null, true), count($map['blocks']))
                    : print_r($map, true);
            }

            $description .= ', blocks of ' . implode(', ', $files);
        }

        if (is_string($result['path'] ?? null)) {
            $description .= is_array($result['pathFeatures'] ?? null)
                ? sprintf(', path %s with %d features', $result['path'], count($result['pathFeatures']))
                : sprintf(', path %s', $result['path']);
        }

        foreach (['retire', 'last'] as $flag) {
            if (($frame[$flag] ?? false) === true) {
                $description .= ', ' . $flag;
            }
        }

        return $description;
    }

    if ($type === 'error') {
        return 'error: ' . print_r($frame['message'] ?? null, true);
    }

    $description = $type;

    foreach (['name', 'maximumLength', 'maximumNumberOfChoices'] as $key) {
        if (isset($frame[$key])) {
            $description .= sprintf(', %s: %s', $key, print_r($frame[$key], true));
        }
    }

    foreach (['targets', 'properties'] as $key) {
        if (is_array($frame[$key] ?? null)) {
            $names = [];

            foreach ($frame[$key] as $element) {
                $names[] = is_array($element) ? print_r($element['name'] ?? null, true) : print_r($element, true);
            }

            $description .= sprintf(', %s: %s', $key, implode(', ', $names));
        }
    }

    return $description;
}
