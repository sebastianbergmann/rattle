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
use function file_put_contents;
use function microtime;
use function printf;
use JsonException;

/**
 * Shows the status of the targets that were fuzzed with the cache directory,
 * fuzz targets and properties of a PHPUnit test suite (see SuiteStatus), as a
 * table, in JSON, or as a dashboard in HTML that is written to a file (see
 * StatusDashboard). It reads the cache directory only, without loading the
 * targets or booting a test suite.
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
            printf('Nothing was fuzzed with the cache directory %s' . PHP_EOL, $arguments->cacheDirectory());

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

        $htmlFile = $arguments->htmlFile();

        if ($htmlFile !== null) {
            if (@file_put_contents($htmlFile, (new StatusDashboard)->render($status, $now)) === false) {
                printf('Cannot write the dashboard to "%s"' . PHP_EOL, $htmlFile);

                return 2;
            }

            printf('The dashboard was written to %s.' . PHP_EOL, $htmlFile);

            return 0;
        }

        print $status->asTable($now);

        return 0;
    }
}
