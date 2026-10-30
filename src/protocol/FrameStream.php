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

use function fflush;
use function fread;
use function fwrite;
use function strlen;
use function substr;

/**
 * Reads and writes frames on blocking streams, which is how a worker
 * exchanges frames with the supervisor over its standard input and output.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class FrameStream
{
    /**
     * @var resource
     */
    private mixed $input;

    /**
     * @var resource
     */
    private mixed $output;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(mixed $input, mixed $output)
    {
        $this->input  = $input;
        $this->output = $output;
    }

    /**
     * Returns null when the stream ended before a frame started.
     *
     * @throws ProtocolException
     *
     * @return ?array<mixed>
     */
    public function read(): ?array
    {
        $prefix = $this->readBytes(4);

        if ($prefix === null) {
            return null;
        }

        $length = FrameBuffer::lengthOf($prefix);

        if ($length === 0) {
            return Frame::decode('');
        }

        $payload = $this->readBytes($length);

        if ($payload === null) {
            throw new ProtocolException('Invalid frame: stream ended within a frame');
        }

        return Frame::decode($payload);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function write(array $message): void
    {
        $frame = Frame::encode($message);

        while ($frame !== '') {
            $written = fwrite($this->output, $frame);

            if ($written === false || $written === 0) {
                return;
            }

            $frame = substr($frame, $written);
        }

        fflush($this->output);
    }

    /**
     * @param positive-int $length
     */
    private function readBytes(int $length): ?string
    {
        $bytes = '';

        while (($remaining = $length - strlen($bytes)) > 0) {
            $chunk = fread($this->input, $remaining);

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }
}
