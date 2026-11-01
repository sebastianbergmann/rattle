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
use const STDERR;
use function explode;
use function fwrite;
use function getcwd;
use function is_file;
use function printf;
use function realpath;
use function rtrim;
use function sprintf;
use Closure;
use SebastianBergmann\Rattle\Instrumentation\SourceResolver;

/**
 * Configures the workers from the command-line arguments.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Setup
{
    /**
     * @throws ConfigurationException
     */
    public function workerConfiguration(Arguments $arguments): WorkerConfiguration
    {
        $target  = $arguments->target();
        $phpunit = null;

        if ($arguments->phpunit()) {
            $configurationFile = $arguments->phpunitConfiguration();

            if ($configurationFile !== null && !is_file($configurationFile)) {
                throw new ConfigurationException(sprintf('The PHPUnit configuration file "%s" does not exist', $configurationFile));
            }

            $phpunit = new PhpunitTestSuite($configurationFile, $arguments->phpunitFilter());
        } elseif ($target === null || $target === '' || !is_file($target)) {
            throw new ConfigurationException(sprintf('The target file "%s" does not exist', (string) $target));
        }

        $workingDirectory = (string) getcwd();
        $bootstrap        = $arguments->bootstrap();

        if ($bootstrap === null) {
            if (is_file($workingDirectory . '/vendor/autoload.php')) {
                $bootstrap = $workingDirectory . '/vendor/autoload.php';
            }
        } elseif (!is_file($bootstrap)) {
            throw new ConfigurationException(sprintf('The bootstrap file "%s" does not exist', $bootstrap));
        }

        $sources = [];

        foreach ((new SourceResolver)->resolve($arguments->sources(), $workingDirectory, $phpunit?->configurationFile()) as $source) {
            $realpath = realpath($source);

            if ($realpath === false) {
                throw new ConfigurationException(sprintf('The source directory "%s" does not exist', $source));
            }

            $sources[] = $realpath;
        }

        return new WorkerConfiguration(
            (string) $target,
            $bootstrap,
            $sources,
            $arguments->timeout(),
            $arguments->maximumNumberOfChoices(),
            $arguments->memoryLimit(),
            $arguments->ignoreDeprecations(),
            $arguments->numberOfExecutionsPerWorker(),
            $phpunit,
        );
    }

    /**
     * The PHP binaries of --php, after checking that they are PHP 8.5 or
     * later.
     *
     * @throws ConfigurationException
     *
     * @return list<PhpBinary>
     */
    public function phpBinaries(Arguments $arguments): array
    {
        $binaries = [];

        foreach ($arguments->phpBinaries() as $path) {
            $binaries[] = PhpBinary::at($path);
        }

        return $binaries;
    }

    /**
     * With --verbose, what the workers write to their standard error streams
     * is shown, prefixed with the number of the worker.
     *
     * @return ?Closure(int, string): void
     */
    public function stderr(Arguments $arguments): ?Closure
    {
        if (!$arguments->verbose()) {
            return null;
        }

        return static function (int $worker, string $bytes): void
        {
            foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
                fwrite(STDERR, sprintf('[worker %d] %s', $worker, $line) . "\n");
            }
        };
    }

    /**
     * Writes the findings of a command in SARIF when --sarif is given, and
     * returns whether this succeeded.
     */
    public function writeSarifReport(?SarifReport $report, ?string $file): bool
    {
        if ($report === null || $file === null) {
            return true;
        }

        try {
            $report->write($file);
        } catch (FindingException $e) {
            print $e->getMessage() . PHP_EOL;

            return false;
        }

        printf(
            '%d finding%s written to %s in SARIF.' . PHP_EOL,
            $report->numberOfResults(),
            $report->numberOfResults() === 1 ? ' was' : 's were',
            $file,
        );

        return true;
    }
}
