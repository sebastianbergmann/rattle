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
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Reads what a SARIF report that rattle wrote says, for assertions.
 */
final readonly class SarifResults
{
    /**
     * @return list<array{ruleId: ?string, ruleIndex: ?int, level: ?string, message: ?string, fingerprint: ?string, uri: ?string, uriBaseId: ?string, startLine: ?int, property: ?string, frames: list<array{0: ?string, 1: ?int, 2: ?string}>}>
     */
    public static function inFile(string $file): array
    {
        return self::in((string) file_get_contents($file));
    }

    /**
     * @return list<array{ruleId: ?string, ruleIndex: ?int, level: ?string, message: ?string, fingerprint: ?string, uri: ?string, uriBaseId: ?string, startLine: ?int, property: ?string, frames: list<array{0: ?string, 1: ?int, 2: ?string}>}>
     */
    public static function in(string $json): array
    {
        $results = [];

        foreach (self::listAt(self::value($json, 'runs', 0, 'results')) as $result) {
            $frames = [];

            foreach (self::listAt(self::at($result, 'stacks', 0, 'frames')) as $frame) {
                $frames[] = [
                    self::stringAt($frame, 'location', 'physicalLocation', 'artifactLocation', 'uri'),
                    self::intAt($frame, 'location', 'physicalLocation', 'region', 'startLine'),
                    self::stringAt($frame, 'location', 'message', 'text'),
                ];
            }

            $results[] = [
                'ruleId'      => self::stringAt($result, 'ruleId'),
                'ruleIndex'   => self::intAt($result, 'ruleIndex'),
                'level'       => self::stringAt($result, 'level'),
                'message'     => self::stringAt($result, 'message', 'text'),
                'fingerprint' => self::stringAt($result, 'partialFingerprints', 'rattleFindingId/v1'),
                'uri'         => self::stringAt($result, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri'),
                'uriBaseId'   => self::stringAt($result, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uriBaseId'),
                'startLine'   => self::intAt($result, 'locations', 0, 'physicalLocation', 'region', 'startLine'),
                'property'    => self::stringAt($result, 'properties', 'property'),
                'frames'      => $frames,
            ];
        }

        return $results;
    }

    /**
     * The rules of the report, by their IDs.
     *
     * @return array<string, array{level: ?string, securitySeverity: ?string, tags: list<mixed>}>
     */
    public static function rules(string $json): array
    {
        $rules = [];

        foreach (self::listAt(self::value($json, 'runs', 0, 'tool', 'driver', 'rules')) as $rule) {
            $rules[(string) self::stringAt($rule, 'id')] = [
                'level'            => self::stringAt($rule, 'defaultConfiguration', 'level'),
                'securitySeverity' => self::stringAt($rule, 'properties', 'security-severity'),
                'tags'             => self::listAt(self::at($rule, 'properties', 'tags')),
            ];
        }

        return $rules;
    }

    /**
     * The value at the keys of the decoded report, or null when there is none.
     */
    public static function value(string $json, int|string ...$keys): mixed
    {
        return self::at(json_decode($json, true, 512, JSON_THROW_ON_ERROR), ...$keys);
    }

    private static function at(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }

    private static function stringAt(mixed $data, int|string ...$keys): ?string
    {
        $value = self::at($data, ...$keys);

        return is_string($value) ? $value : null;
    }

    private static function intAt(mixed $data, int|string ...$keys): ?int
    {
        $value = self::at($data, ...$keys);

        return is_int($value) ? $value : null;
    }

    /**
     * @return list<mixed>
     */
    private static function listAt(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $element) {
            $list[] = $element;
        }

        return $list;
    }
}
