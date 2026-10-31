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

use function microtime;
use function sprintf;
use function stream_select;
use function trim;

/**
 * Lists the test IDs of the properties of a PHPUnit test suite. A worker
 * process loads the test suite, so that the supervisor does not load the code
 * of the project.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PropertyLister
{
    /**
     * How long the supervisor waits for the worker to list the properties.
     */
    private const float TIMEOUT = 120.0;

    /**
     * @throws WorkerException
     *
     * @return list<non-empty-string>
     */
    public function list(WorkerConfiguration $configuration): array
    {
        $worker   = WorkerProcess::forConfiguration($configuration->withTarget(''));
        $deadline = microtime(true) + self::TIMEOUT;

        while (microtime(true) < $deadline) {
            $streams = [$worker->stdout(), $worker->stderr()];
            $write   = null;
            $except  = null;

            if (@stream_select($streams, $write, $except, 1) === false) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $worker->readStderr();

            try {
                $frames = $worker->readFrames();
            } catch (ProtocolException $e) {
                $worker->kill();
                $worker->wait();

                throw new WorkerException(sprintf('The properties cannot be listed: %s', $e->getMessage()), 0, $e);
            }

            if ($frames === null) {
                $worker->wait();

                throw new WorkerException($this->failure('The worker process ended before it listed the properties', $worker));
            }

            foreach ($frames as $frame) {
                if (($frame['type'] ?? null) === 'error') {
                    $worker->wait();

                    throw new WorkerException($this->failure(sprintf('The properties cannot be listed: %s', ProtocolData::string($frame['message'] ?? null)), $worker));
                }

                if (($frame['type'] ?? null) === 'properties') {
                    $worker->wait();

                    return $this->propertiesIn($frame);
                }
            }
        }

        $worker->kill();
        $worker->wait();

        throw new WorkerException('The worker did not list the properties in time');
    }

    /**
     * @param array<mixed> $frame
     *
     * @throws WorkerException
     *
     * @return list<non-empty-string>
     */
    private function propertiesIn(array $frame): array
    {
        if (($frame['protocol'] ?? null) !== Frame::PROTOCOL_VERSION) {
            throw new WorkerException('The worker speaks another version of the protocol');
        }

        try {
            $properties = [];

            foreach (ProtocolData::listOfStrings($frame['properties'] ?? null) as $property) {
                $properties[] = ProtocolData::nonEmptyString($property);
            }
        } catch (ProtocolException $e) {
            throw new WorkerException(sprintf('The worker sent an invalid list of properties: %s', $e->getMessage()), 0, $e);
        }

        return $properties;
    }

    private function failure(string $message, WorkerProcess $worker): string
    {
        $stderr = trim($worker->stderrTail());

        if ($stderr === '') {
            return $message;
        }

        return $message . "\n\n" . $stderr;
    }
}
