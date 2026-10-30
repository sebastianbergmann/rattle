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

use const STDERR;
use function explode;
use function fwrite;
use function getcwd;
use function is_file;
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
        $target = $arguments->target();

        if ($target === null || $target === '' || !is_file($target)) {
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

        foreach ((new SourceResolver)->resolve($arguments->sources(), $workingDirectory) as $source) {
            $realpath = realpath($source);

            if ($realpath === false) {
                throw new ConfigurationException(sprintf('The source directory "%s" does not exist', $source));
            }

            $sources[] = $realpath;
        }

        return new WorkerConfiguration(
            $target,
            $bootstrap,
            $sources,
            $arguments->timeout(),
            $arguments->maximumNumberOfChoices(),
            $arguments->memoryLimit(),
            $arguments->ignoreDeprecations(),
            $arguments->numberOfExecutionsPerWorker(),
        );
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
}
