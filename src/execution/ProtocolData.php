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

use function array_is_list;
use function is_array;
use function is_int;
use function is_string;

/**
 * Reads values of the expected types from the data of a protocol message.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class ProtocolData
{
    /**
     * @throws ProtocolException
     *
     * @return list<non-negative-int>
     */
    public static function listOfNonNegativeIntegers(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProtocolException('Expected a list of non-negative integers');
        }

        foreach ($value as $element) {
            if (!is_int($element) || $element < 0) {
                throw new ProtocolException('Expected a list of non-negative integers');
            }
        }

        return $value;
    }

    /**
     * @throws ProtocolException
     *
     * @return list<int>
     */
    public static function listOfIntegers(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProtocolException('Expected a list of integers');
        }

        foreach ($value as $element) {
            if (!is_int($element)) {
                throw new ProtocolException('Expected a list of integers');
            }
        }

        return $value;
    }

    /**
     * @throws ProtocolException
     *
     * @return list<string>
     */
    public static function listOfStrings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProtocolException('Expected a list of strings');
        }

        foreach ($value as $element) {
            if (!is_string($element)) {
                throw new ProtocolException('Expected a list of strings');
            }
        }

        return $value;
    }

    /**
     * @throws ProtocolException
     */
    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ProtocolException('Expected a string');
        }

        return $value;
    }

    /**
     * @throws ProtocolException
     *
     * @return non-empty-string
     */
    public static function nonEmptyString(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new ProtocolException('Expected a non-empty string');
        }

        return $value;
    }

    /**
     * @throws ProtocolException
     *
     * @return array<mixed>
     */
    public static function array(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ProtocolException('Expected an array');
        }

        return $value;
    }
}
