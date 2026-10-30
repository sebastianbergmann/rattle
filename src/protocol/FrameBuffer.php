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

use function is_int;
use function strlen;
use function substr;
use function unpack;

/**
 * Collects the bytes that are read from a stream, which may arrive in
 * arbitrary chunks, and returns the frames that are complete.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class FrameBuffer
{
    private string $buffer = '';

    /**
     * @throws ProtocolException
     *
     * @return non-negative-int
     */
    public static function lengthOf(string $prefix): int
    {
        $unpacked = unpack('N', $prefix);

        $length = $unpacked[1] ?? null;

        if (!is_int($length) || $length < 0 || $length > Frame::MAXIMUM_LENGTH) {
            throw new ProtocolException('Invalid frame: invalid length');
        }

        return $length;
    }

    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * @throws ProtocolException
     *
     * @return list<array<mixed>>
     */
    public function frames(): array
    {
        $frames = [];

        while (strlen($this->buffer) >= 4) {
            $length = self::lengthOf(substr($this->buffer, 0, 4));

            if (strlen($this->buffer) < 4 + $length) {
                break;
            }

            $frames[] = Frame::decode(substr($this->buffer, 4, $length));

            $this->buffer = substr($this->buffer, 4 + $length);
        }

        return $frames;
    }

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }
}
