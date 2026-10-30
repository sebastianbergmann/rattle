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
use function array_slice;
use function dirname;
use function printf;
use SebastianBergmann\Version;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Application
{
    private const string VERSION = '1.0.0';

    public static function version(): string
    {
        return new Version(self::VERSION, dirname(__DIR__, 2))->asString();
    }

    /**
     * @param list<string> $argv
     *
     * @return 0|1|2
     */
    public function run(array $argv): int
    {
        if (($argv[1] ?? null) === 'worker') {
            return (new WorkerCommand)->run(array_slice($argv, 2));
        }

        $this->printVersion();

        try {
            $arguments = (new ArgumentsBuilder)->build($argv);
        } catch (Exception $e) {
            print PHP_EOL . $e->getMessage() . PHP_EOL;

            return 2;
        }

        if ($arguments->version()) {
            return 0;
        }

        print PHP_EOL;

        if ($arguments->help()) {
            return (new HelpCommand)->run($arguments);
        }

        (new HelpCommand)->run($arguments);

        return 2;
    }

    private function printVersion(): void
    {
        printf(
            'rattle %s by Sebastian Bergmann.' . PHP_EOL,
            self::version(),
        );
    }
}
