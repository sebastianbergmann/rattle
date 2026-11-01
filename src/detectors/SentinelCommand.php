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

use const GLOB_ONLYDIR;
use const SIG_DFL;
use const SIGUSR2;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function getenv;
use function getmypid;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function putenv;
use function random_bytes;
use function register_shutdown_function;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;
use Closure;

/**
 * The sentinel command of the detectors (see Detectors): an executable named
 * rattle-sentinel in a directory of its own, which is appended to the PATH of
 * the worker, and thereby to the PATH of every process that the code under
 * test starts, whether it runs the command itself or has a shell interpret a
 * command line.
 *
 * When the command runs, it sends SIGUSR2 to the worker. With asynchronous
 * signals, PHP calls the handler of the signal in the frame of the code that
 * ran the command, once the function that ran it returns, as it does for the
 * alarm of the time limit.
 *
 * A worker removes its directory when it exits, but a worker that is killed
 * cannot. The workers of a supervisor therefore create their directories in
 * a directory of the supervisor, which the supervisor removes, with all that
 * is left in it, when it exits.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class SentinelCommand
{
    private const string DIRECTORY_VARIABLE    = 'RATTLE_SENTINEL_DIRECTORY';
    private static ?string $directory          = null;
    private static ?string $directoryOfWorkers = null;
    private static false|string $path          = false;
    private static bool $asynchronousSignals   = false;

    /**
     * @var callable|int
     */
    private static mixed $previousHandler = SIG_DFL;

    /**
     * @param Closure(): void $handler is called when the sentinel command ran
     */
    public static function install(Closure $handler): void
    {
        if (self::$directory !== null) {
            return;
        }

        $pid       = (int) getmypid();
        $parent    = getenv(self::DIRECTORY_VARIABLE);
        $directory = ($parent !== false && is_dir($parent) ? $parent : sys_get_temp_dir()) . '/rattle-sentinel-' . $pid . '-' . bin2hex(random_bytes(4));
        $command   = $directory . '/' . Sentinels::NAME;

        mkdir($directory, 0o700);
        file_put_contents($command, sprintf("#!/bin/sh\nkill -USR2 %d\n", $pid));
        chmod($command, 0o700);

        self::$directory           = $directory;
        self::$path                = getenv('PATH');
        self::$asynchronousSignals = pcntl_async_signals(true);
        self::$previousHandler     = pcntl_signal_get_handler(SIGUSR2);

        self::setPath(self::$path === false || self::$path === '' ? $directory : self::$path . ':' . $directory);

        pcntl_signal(
            SIGUSR2,
            static function () use ($handler): void
            {
                $handler();
            },
        );

        register_shutdown_function(self::uninstall(...));
    }

    public static function uninstall(): void
    {
        if (self::$directory === null) {
            return;
        }

        $command = self::$directory . '/' . Sentinels::NAME;

        if (is_file($command)) {
            unlink($command);
        }

        @rmdir(self::$directory);

        self::$directory = null;

        if (self::$path === false) {
            putenv('PATH');
        } else {
            self::setPath(self::$path);
        }

        pcntl_signal(SIGUSR2, self::$previousHandler);
        pcntl_async_signals(self::$asynchronousSignals);
    }

    /**
     * The environment variables that tell the workers that the supervisor
     * starts in which directory they create the directories of their sentinel
     * commands. The directory is created when it is first needed.
     *
     * @return array<string, string>
     */
    public static function environmentOfWorkers(): array
    {
        if (self::$directoryOfWorkers === null) {
            $directory = sys_get_temp_dir() . '/rattle-sentinels-' . (int) getmypid() . '-' . bin2hex(random_bytes(4));

            if (!@mkdir($directory, 0o700)) {
                // @codeCoverageIgnoreStart
                return [];
                // @codeCoverageIgnoreEnd
            }

            self::$directoryOfWorkers = $directory;

            register_shutdown_function(self::removeDirectoryOfWorkers(...));
        }

        return [self::DIRECTORY_VARIABLE => self::$directoryOfWorkers];
    }

    /**
     * Removes the directory of the workers, with the directories of their
     * sentinel commands that are left in it.
     */
    public static function removeDirectoryOfWorkers(): void
    {
        if (self::$directoryOfWorkers === null) {
            return;
        }

        foreach ((array) glob(self::$directoryOfWorkers . '/*', GLOB_ONLYDIR) as $directory) {
            @unlink($directory . '/' . Sentinels::NAME);
            @rmdir((string) $directory);
        }

        @rmdir(self::$directoryOfWorkers);

        self::$directoryOfWorkers = null;
    }

    private static function setPath(string $path): void
    {
        putenv('PATH=' . $path);

        // code that starts processes may take their environment from these
        if (isset($_ENV['PATH'])) {
            $_ENV['PATH'] = $path;
        }

        if (isset($_SERVER['PATH'])) {
            $_SERVER['PATH'] = $path;
        }
    }
}
