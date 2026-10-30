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

use function get_debug_type;
use function is_string;
use function ord;
use function preg_match;
use function sprintf;
use function strlen;
use function var_export;
use Throwable;

/**
 * Renders a drawn value as PHP code with var_export(). A string that is not
 * valid UTF-8 or that contains control characters is rendered as a string
 * literal in double quotes with escape sequences instead, so that the rendered
 * value is readable and can be encoded as JSON.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class ValueExporter
{
    public function export(mixed $value): string
    {
        if (is_string($value)) {
            return $this->exportString($value);
        }

        try {
            // var_export() warns about circular references, which must not
            // be reported as an issue of the code that is fuzzed
            return @var_export($value, true);
        } catch (Throwable) {
            return get_debug_type($value);
        }
    }

    private function exportString(string $value): string
    {
        if (preg_match('/\A[^\x00-\x1F\x7F]*\z/u', $value) === 1) {
            return var_export($value, true);
        }

        $exported = '"';

        for ($i = 0; $i < strlen($value); $i++) {
            $byte = ord($value[$i]);

            $exported .= match (true) {
                $byte === 0x09                 => '\t',
                $byte === 0x0A                 => '\n',
                $byte === 0x0D                 => '\r',
                $byte === 0x22                 => '\"',
                $byte === 0x24                 => '\$',
                $byte === 0x5C                 => '\\\\',
                $byte >= 0x20 && $byte <= 0x7E => $value[$i],
                default                        => sprintf('\x%02X', $byte),
            };
        }

        return $exported . '"';
    }
}
