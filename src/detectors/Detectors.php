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

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use function class_exists;
use function debug_backtrace;
use function getcwd;
use function is_file;
use function sprintf;
use function str_contains;
use Closure;
use SebastianBergmann\Rattle\Instrumentation\FileStreamWrapper;

/**
 * Detects that an input controls a dangerous sink: not when the code under
 * test merely reaches the sink, but when the input makes it use a sentinel
 * there (see Sentinels), which only the input can have put there. A detection
 * is a vulnerability for which the input is the proof:
 *
 * - path traversal: a file operation on a path that leads to the sentinel of
 *   the file system, which the worker's stream wrapper for file:// sees for
 *   every operation on files, in the code under test as well as in the code
 *   that it uses
 * - code injection: an included file whose path leads to the sentinel of the
 *   file system, or an object of the sentinel class that is created with new,
 *   for instance in code that eval() evaluates (see Sentinel)
 * - unsafe deserialization: an object of the sentinel class that unserialize()
 *   creates
 * - command injection: a run of the sentinel command, as a command or in a
 *   command line that a shell interprets (see SentinelCommand)
 *
 * A detection throws a Vulnerability into the code under test, at the frame
 * of the sink, and is recorded as the failure of the input that is executed.
 * The first detection during the execution of an input is how the input
 * fails, even when the code under test catches the vulnerability.
 *
 * A detection is reported at the innermost frame of the stack that lies in
 * the source directories: the code of the project that passed the data of the
 * input on to the sink, whether the sink is called there or in code that it
 * uses. When no frame lies in the source directories, it is reported at the
 * innermost frame that is not rattle's own code, such as the fuzz target that
 * passes the input to the sink itself.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Detectors
{
    private static ?Location $location = null;

    /**
     * @var ?Closure(string): bool
     */
    private static ?Closure $isSource  = null;
    private static bool $active        = false;
    private static ?Failure $detection = null;

    /**
     * Installs the detectors in a worker.
     *
     * @param Closure(string): bool $isSource tells whether a file lies in the source directories
     */
    public static function install(Location $location, Closure $isSource): void
    {
        self::$location = $location;
        self::$isSource = $isSource;

        // the classes that the observer uses are loaded before it observes
        // the files that are included, so that it does not observe the
        // inclusion of their own files while it needs them
        foreach ([Sentinels::class, Failure::class, PathTraversal::class, CodeInjection::class] as $class) {
            class_exists($class);
        }

        FileStreamWrapper::observe(self::checkPath(...));
        SentinelCommand::install(self::detectCommand(...));
    }

    public static function uninstall(): void
    {
        FileStreamWrapper::observe(null);
        SentinelCommand::uninstall();

        self::$location  = null;
        self::$isSource  = null;
        self::$active    = false;
        self::$detection = null;
    }

    /**
     * Starts detecting, before an input is executed.
     */
    public static function start(): void
    {
        self::$detection = null;
        self::$active    = true;
    }

    /**
     * Stops detecting, after an input was executed, and returns the failure
     * of the first detection during its execution, or null when nothing was
     * detected.
     */
    public static function stop(): ?Failure
    {
        self::$active = false;

        return self::$detection;
    }

    /**
     * The failure of the first detection during the execution of the input
     * that is executed, or null when nothing was detected.
     */
    public static function detection(): ?Failure
    {
        return self::$detection;
    }

    /**
     * Records a detection, unless something was detected during the
     * execution of the input before, and returns the vulnerability that is
     * thrown into the code under test.
     *
     * @param class-string<Vulnerability> $class
     */
    public static function detect(string $class, string $message): Vulnerability
    {
        $frames        = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        [$file, $line] = self::frameOf($frames);
        $vulnerability = new $class($message, $file, $line);

        if (self::$active && self::$detection === null) {
            $location = self::$location ?? new Location((string) getcwd());

            self::$detection = Failure::throwable(
                $class,
                $location->ofIssue($file, $line),
                $message,
                $location->render($frames),
            );
        }

        return $vulnerability;
    }

    /**
     * Is called by the stream wrapper for file:// with the path of every
     * operation on a file or a directory.
     *
     * @throws Vulnerability
     */
    public static function checkPath(string $path, bool $include): void
    {
        // the path is only resolved when it can lead to the sentinel
        if (!self::$active || !str_contains($path, Sentinels::NAME) || !Sentinels::isPath($path, (string) getcwd())) {
            return;
        }

        if ($include) {
            throw self::detect(
                CodeInjection::class,
                sprintf('The path "%s" of a file that is included leads to the sentinel /%s', $path, Sentinels::NAME),
            );
        }

        throw self::detect(
            PathTraversal::class,
            sprintf('The path "%s" of a file operation leads to the sentinel /%s', $path, Sentinels::NAME),
        );
    }

    /**
     * Is called when the sentinel command ran.
     *
     * @throws CommandInjection
     */
    private static function detectCommand(): void
    {
        if (!self::$active) {
            return;
        }

        throw self::detect(
            CommandInjection::class,
            sprintf('A command from the input ran the sentinel command %s', Sentinels::NAME),
        );
    }

    /**
     * @param list<array{file?: string, line?: int, ...}> $frames
     *
     * @return array{0: non-empty-string, 1: int}
     */
    private static function frameOf(array $frames): array
    {
        $fallback = null;

        foreach ($frames as $frame) {
            // the code that eval() evaluates has no file of its own
            if (!isset($frame['file'], $frame['line']) ||
                $frame['file'] === '' ||
                Location::isRattle($frame['file']) ||
                !is_file($frame['file'])) {
                continue;
            }

            if (self::$isSource !== null && (self::$isSource)($frame['file'])) {
                return [$frame['file'], $frame['line']];
            }

            $fallback ??= [$frame['file'], $frame['line']];
        }

        // @codeCoverageIgnoreStart
        return $fallback ?? [__FILE__, __LINE__];
        // @codeCoverageIgnoreEnd
    }
}
