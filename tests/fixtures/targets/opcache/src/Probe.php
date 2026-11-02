<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\opcache;

use function function_exists;
use function ini_get;
use function opcache_is_script_cached;
use RuntimeException;
use SebastianBergmann\Rattle\Instrumentation\Edges;

/**
 * Checks, from code that rattle instruments, that the worker process runs
 * the instrumented code from OPcache, and that the file cache of OPcache is
 * turned off.
 */
final class Probe
{
    public function check(): void
    {
        if (Edges::hits() === []) {
            throw new RuntimeException('The code of the probe is not instrumented');
        }

        if (!function_exists('opcache_is_script_cached') || !opcache_is_script_cached(__FILE__)) {
            throw new RuntimeException('OPcache did not cache the instrumented code of the probe');
        }

        if (ini_get('opcache.file_cache') !== '') {
            throw new RuntimeException('The file cache of OPcache is not turned off');
        }
    }
}
