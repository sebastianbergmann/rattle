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

use function hash;
use function preg_replace;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * The name of the directories of the corpus and the findings of a PHPUnit
 * property: the name of its test class without its namespace and the name of
 * its test method, in the characters that the names of fuzz targets have,
 * followed by a hash of its test ID that tells properties apart whose names
 * are the same.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PropertyDirectory
{
    /**
     * @param non-empty-string $id the test ID of the property
     *
     * @return non-empty-string
     */
    public static function nameOf(string $id): string
    {
        $separator = strrpos($id, '\\');
        $name      = $separator === false ? $id : substr($id, $separator + 1);
        $name      = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-');

        return ($name === '' ? '' : $name . '-') . substr(hash('sha256', $id), 0, 8);
    }
}
