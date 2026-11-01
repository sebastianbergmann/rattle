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

use const JSON_THROW_ON_ERROR;
use function array_key_exists;
use function is_array;
use function json_decode;

/**
 * Reads values from JSON that rattle wrote, for assertions.
 */
final readonly class JsonValue
{
    /**
     * The value at the keys of the decoded JSON, or null when there is none.
     */
    public static function of(string $json, int|string ...$keys): mixed
    {
        return self::at(json_decode($json, true, 512, JSON_THROW_ON_ERROR), ...$keys);
    }

    /**
     * The value at the keys of decoded JSON, or null when there is none.
     */
    public static function at(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }
}
