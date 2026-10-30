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

use const PHP_OS_FAMILY;
use function file_get_contents;
use function is_readable;
use function shell_exec;
use function substr_count;
use function trim;

/**
 * Determines the number of CPU cores, or 1 when it cannot be determined.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class ProcessorCount
{
    /**
     * @return positive-int
     */
    public function count(): int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->positive((string) @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null'));
        }

        if (is_readable('/proc/cpuinfo')) {
            $count = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1;

            if ($count > 1) {
                return $count;
            }
        }

        return $this->positive((string) @shell_exec('nproc 2>/dev/null'));
    }

    /**
     * @return positive-int
     */
    private function positive(string $output): int
    {
        $count = (int) trim($output);

        if ($count < 1) {
            return 1;
        }

        return $count;
    }
}
