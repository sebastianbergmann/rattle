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

use const PHP_EOL;
use function microtime;
use function printf;
use JsonException;

/**
 * Shows the status of the properties of a PHPUnit test suite that were
 * fuzzed with the cache directory (see SuiteStatus), as a table, or in JSON.
 * It reads the cache directory only, without booting the test suite.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class StatusCommand implements Command
{
    public function run(Arguments $arguments): int
    {
        $status = SuiteStatus::fromCacheDirectory($arguments->cacheDirectory());

        if ($status->isEmpty()) {
            printf('No property of a PHPUnit test suite was fuzzed with the cache directory %s' . PHP_EOL, $arguments->cacheDirectory());

            return 2;
        }

        $now = microtime(true);

        if ($arguments->json()) {
            try {
                print $status->asJson($now);
            } catch (JsonException $e) {
                // @codeCoverageIgnoreStart
                print $e->getMessage() . PHP_EOL;

                return 2;
                // @codeCoverageIgnoreEnd
            }

            return 0;
        }

        print $status->asTable($now);

        return 0;
    }
}
