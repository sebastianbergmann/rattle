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

use function array_unshift;
use function defined;
use function dirname;
use function implode;
use function is_string;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use Error;
use Throwable;

/**
 * Determines where a throwable was raised and renders stack traces, leaving
 * out rattle's own code.
 *
 * The location of a throwable is its own file and line, unless these lie in
 * rattle's own code. Then it is the first frame of its stack trace that does
 * not. This is how PHPUnit determines the location of a throwable with its
 * own code left out. When rattle runs from a PHAR, the code of the
 * dependencies that are bundled in the PHAR is rattle's own code, too.
 *
 * When unbounded recursion reaches the limit of PHP's call stack, the error is
 * raised by whichever call of the recursion exceeds the limit. Which call that
 * is depends on how deep the call stack was when the recursion started, and
 * on how much of it the calls use, which differs between versions of PHP. So
 * that the same recursion has the same location, the location of this error
 * is the frame of the recursion that comes first, by file and then by line,
 * among the frames that occur more than once in its stack trace.
 *
 * The path of a file below the working directory is relative to it, so that
 * failure signatures, and the identifiers of findings that are derived from
 * them, do not depend on where a project is checked out. The working
 * directory is determined once, before the code under test could change it.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Location
{
    /**
     * The message of the error that PHP raises when the call stack limit is
     * reached starts with this.
     */
    private const string CALL_STACK_LIMIT = 'Maximum call stack size of ';
    private string $prefix;

    public static function isRattle(string $file): bool
    {
        if (defined('__RATTLE_PHAR__') && is_string(__RATTLE_PHAR__)) {
            // @codeCoverageIgnoreStart
            return $file === __RATTLE_PHAR__ ||
                   str_starts_with($file, 'phar://' . __RATTLE_PHAR__ . '/');
            // @codeCoverageIgnoreEnd
        }

        return str_starts_with($file, dirname(__DIR__) . '/') ||
               $file === dirname(__DIR__, 2) . '/rattle';
    }

    public function __construct(string $workingDirectory)
    {
        $realpath = realpath($workingDirectory);

        if ($realpath === false) {
            $realpath = $workingDirectory;
        }

        $this->prefix = rtrim($realpath, '/') . '/';
    }

    /**
     * @return non-empty-string
     */
    public function of(Throwable $throwable): string
    {
        $frames = [];

        foreach ($this->framesOf($throwable) as $frame) {
            if (!self::isRattle($frame['file'])) {
                $frames[] = ['file' => $this->file($frame['file']), 'line' => $frame['line']];
            }
        }

        if ($frames === []) {
            return $this->file($throwable->getFile()) . ':' . $throwable->getLine();
        }

        $frame = $frames[0];

        if ($throwable::class === Error::class && str_starts_with($throwable->getMessage(), self::CALL_STACK_LIMIT)) {
            $frame = self::firstRepeatedFrame($frames) ?? $frame;
        }

        return $frame['file'] . ':' . $frame['line'];
    }

    /**
     * @return non-empty-string
     */
    public function ofIssue(string $file, int $line): string
    {
        return $this->file($file) . ':' . $line;
    }

    public function traceOf(Throwable $throwable): string
    {
        $frames = $throwable->getTrace();

        array_unshift($frames, ['file' => $throwable->getFile(), 'line' => $throwable->getLine()]);

        return $this->render($frames);
    }

    /**
     * @param list<array{file?: string, line?: int, class?: class-string, type?: string, function?: string, ...}> $frames
     */
    public function render(array $frames): string
    {
        $lines = [];

        foreach ($frames as $frame) {
            if (!isset($frame['file']) || self::isRattle($frame['file'])) {
                continue;
            }

            $function = '';

            if (isset($frame['function'])) {
                $function = sprintf(' %s%s%s()', $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
            }

            $lines[] = sprintf('%s:%d%s', $this->file($frame['file']), $frame['line'] ?? 0, $function);
        }

        return implode("\n", $lines);
    }

    /**
     * The path of the file, relative to the working directory when the file
     * is below it.
     */
    public function file(string $path): string
    {
        if (str_starts_with($path, $this->prefix)) {
            return substr($path, strlen($this->prefix));
        }

        return $path;
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private function framesOf(Throwable $throwable): array
    {
        $frames = [['file' => $throwable->getFile(), 'line' => $throwable->getLine()]];

        foreach ($throwable->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return $frames;
    }

    /**
     * Returns the frame that comes first, by file and then by line, among the
     * frames that occur more than once, or null when no frame does.
     *
     * @param list<array{file: string, line: int}> $frames
     *
     * @return ?array{file: string, line: int}
     */
    private static function firstRepeatedFrame(array $frames): ?array
    {
        $occurrences = [];

        foreach ($frames as $frame) {
            $key               = $frame['file'] . ':' . $frame['line'];
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
        }

        $first = null;

        foreach ($frames as $frame) {
            if (($occurrences[$frame['file'] . ':' . $frame['line']] ?? 0) < 2) {
                continue;
            }

            if ($first === null) {
                $first = $frame;

                continue;
            }

            $order = strcmp($frame['file'], $first['file']);

            if ($order < 0 || ($order === 0 && $frame['line'] < $first['line'])) {
                $first = $frame;
            }
        }

        return $first;
    }
}
