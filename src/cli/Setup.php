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
use function assert;
use function count;
use function explode;
use function fwrite;
use function getcwd;
use function is_dir;
use function is_file;
use function printf;
use function realpath;
use function rtrim;
use function scandir;
use function sprintf;
use function str_ends_with;
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
     * The configuration of the workers. When the command is given several
     * target files, or a directory of target files, the workers load all of
     * them, and with one target file, that one.
     *
     * @throws ConfigurationException
     */
    public function workerConfiguration(Arguments $arguments): WorkerConfiguration
    {
        $target           = $arguments->target();
        $phpunit          = null;
        $targets          = [];
        $workingDirectory = (string) getcwd();
        $bootstrap        = $arguments->bootstrap();

        if ($bootstrap === null && is_file($workingDirectory . '/vendor/autoload.php')) {
            $bootstrap = $workingDirectory . '/vendor/autoload.php';
        }

        if ($arguments->phpunit()) {
            $configurationFile = $arguments->phpunitConfiguration();

            if ($configurationFile !== null && !is_file($configurationFile)) {
                throw new ConfigurationException(sprintf('The PHPUnit configuration file "%s" does not exist', $configurationFile));
            }

            $phpunit = new PhpunitTestSuite($configurationFile, $arguments->phpunitFilter());
        } else {
            $targets = $this->targetFiles($arguments, $bootstrap);
            $target  = count($targets) === 1 ? $targets[0] : '';
        }

        if ($bootstrap !== null && !is_file($bootstrap)) {
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
            $arguments->opcache(),
            count($targets) > 1 ? $targets : [],
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

    /**
     * The target files of the targets that the command is given, in the order
     * in which they are given, each once. For the commands that fuzz or
     * replay several targets, a directory stands for the PHP files directly
     * in it, in the order of their names, except the bootstrap file.
     *
     * @throws ConfigurationException
     *
     * @return non-empty-list<string>
     */
    private function targetFiles(Arguments $arguments, ?string $bootstrap): array
    {
        $files     = [];
        $realpaths = [];
        $excluded  = $bootstrap === null ? false : realpath($bootstrap);

        foreach ($arguments->targets() === [] ? [''] : $arguments->targets() as $target) {
            if ($target !== '' && is_file($target)) {
                $given = [$target];
            } elseif ($target !== '' && is_dir($target) && ($arguments->command() === 'fuzz' || $arguments->command() === 'regress')) {
                $given   = [];
                $entries = scandir($target);

                foreach ($entries === false ? [] : $entries as $entry) {
                    $file = rtrim($target, '/') . '/' . $entry;

                    if (str_ends_with($entry, '.php') && is_file($file) && realpath($file) !== $excluded) {
                        $given[] = $file;
                    }
                }

                if ($given === []) {
                    throw new ConfigurationException(sprintf('The directory "%s" contains no target files', $target));
                }
            } else {
                throw new ConfigurationException(sprintf('The target file "%s" does not exist', $target));
            }

            foreach ($given as $file) {
                $realpath = (string) realpath($file);

                if (!isset($realpaths[$realpath])) {
                    $realpaths[$realpath] = true;
                    $files[]              = $file;
                }
            }
        }

        assert($files !== []);

        return $files;
    }
}
