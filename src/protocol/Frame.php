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

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use function is_array;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;
use JsonException;

/**
 * A message between the supervisor and a worker: a length prefix of four
 * bytes in network byte order, followed by a JSON payload. Only data is
 * exchanged, never serialized PHP objects.
 *
 * Strings that are not valid UTF-8, for instance in the message of an
 * exception, are encoded with the replacement character.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Frame
{
    public const int PROTOCOL_VERSION = 1;

    /**
     * Frames that are larger than this are rejected.
     */
    public const int MAXIMUM_LENGTH = 256 * 1024 * 1024;

    /**
     * @param array<string, mixed> $message
     */
    public static function encode(array $message): string
    {
        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return pack('N', strlen($payload)) . $payload;
    }

    /**
     * @throws ProtocolException
     *
     * @return array<mixed>
     */
    public static function decode(string $payload): array
    {
        try {
            $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProtocolException('Invalid frame: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($message)) {
            throw new ProtocolException('Invalid frame: not an object');
        }

        return $message;
    }
}
