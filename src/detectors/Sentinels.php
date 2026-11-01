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

use function array_pop;
use function explode;
use function str_starts_with;
use function substr;

/**
 * The sentinels of the detectors (see Detectors): values that the code under
 * test only uses when an input put them there: the sentinel of the file
 * system, and the sentinel class (see Sentinel).
 *
 * The sentinel of the file system is /rattle-sentinel, a path at the root of
 * the file system that no project uses, which a relative path reaches with
 * enough ../ from every directory, and which is never touched: the detector
 * reports the path before PHP operates on it.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Sentinels
{
    public const string NAME = 'rattle-sentinel';

    /**
     * Whether the path leads to the sentinel of the file system, possibly
     * followed by more, such as an extension that the code under test
     * appended to it. A relative path is resolved against the working
     * directory, and . and .. are resolved without asking the file system.
     */
    public static function isPath(string $path, string $workingDirectory): bool
    {
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }

        if ($path === '') {
            return false;
        }

        if (!str_starts_with($path, '/')) {
            $path = $workingDirectory . '/' . $path;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return isset($segments[0]) && str_starts_with($segments[0], self::NAME);
    }
}
