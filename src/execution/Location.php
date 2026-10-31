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

use function array_slice;
use function array_unshift;
use function count;
use function defined;
use function dirname;
use function implode;
use function in_array;
use function is_file;
use function is_string;
use function number_format;
use function range;
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
 * The location of a throwable is the first of its frames that does not lie
 * in rattle's own code: its own file and line, unless its stack trace
 * contains them already, followed by the frames of its stack trace. Frames
 * whose file is not a file, such as the code that eval() evaluates, are left
 * out. This is how PHPUnit determines the location of a throwable with its
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
 * Consecutive repetitions of the same frames, such as the thousands of frames
 * of a recursion that reached the limit of the call stack, are rendered once,
 * followed by how often they are repeated.
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

    /**
     * How often frames have to be repeated in a stack trace to be rendered once.
     */
    private const int MINIMUM_REPETITIONS = 3;

    /**
     * The maximum number of frames that are rendered once when they are
     * repeated.
     */
    private const int MAXIMUM_CYCLE_LENGTH = 64;
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
        $frames = $this->framesOf($throwable);

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

        return implode("\n", self::collapse($lines));
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
     * Returns the frames of the throwable that do not lie in rattle's own
     * code and whose file is a file, with the paths of their files relative
     * to the working directory: its own file and line, unless its stack trace
     * contains them already, followed by the frames of its stack trace.
     *
     * @return list<array{file: string, line: int}>
     */
    private function framesOf(Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        $own = ['file' => $throwable->getFile(), 'line' => $throwable->getLine()];

        if (!in_array($own, $frames, true)) {
            array_unshift($frames, $own);
        }

        $isFile = [];
        $result = [];

        foreach ($frames as $frame) {
            if (self::isRattle($frame['file'])) {
                continue;
            }

            // the frames of a recursion lie in a few files
            $isFile[$frame['file']] ??= is_file($frame['file']);

            if ($isFile[$frame['file']]) {
                $result[] = ['file' => $this->file($frame['file']), 'line' => $frame['line']];
            }
        }

        return $result;
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

    /**
     * Renders consecutive repetitions of the same lines once, followed by a
     * line that tells how often they are repeated.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function collapse(array $lines): array
    {
        $collapsed = [];
        $position  = 0;

        while ($position < count($lines)) {
            [$length, $repetitions] = self::repetitionsAt($lines, $position);

            if ($repetitions < self::MINIMUM_REPETITIONS) {
                $collapsed[] = $lines[$position] ?? '';

                $position++;

                continue;
            }

            foreach (array_slice($lines, $position, $length) as $line) {
                $collapsed[] = $line;
            }

            $collapsed[] = sprintf(
                '(the %s above %s repeated %s more times)',
                $length === 1 ? 'frame' : $length . ' frames',
                $length === 1 ? 'is' : 'are',
                number_format($repetitions - 1),
            );

            $position += $length * $repetitions;
        }

        return $collapsed;
    }

    /**
     * Returns the number of lines that are repeated, starting at the position,
     * and how often they are, for the repetition that covers the most lines.
     *
     * @param list<string> $lines
     *
     * @return array{0: positive-int, 1: positive-int}
     */
    private static function repetitionsAt(array $lines, int $position): array
    {
        $best     = [1, 1];
        $coverage = 0;

        foreach (range(1, self::MAXIMUM_CYCLE_LENGTH) as $length) {
            if ($position + 2 * $length > count($lines)) {
                break;
            }

            $repetitions = 1;

            while (self::isRepeated($lines, $position, $length, $repetitions)) {
                $repetitions++;
            }

            if ($repetitions >= self::MINIMUM_REPETITIONS && $length * $repetitions > $coverage) {
                $best     = [$length, $repetitions];
                $coverage = $length * $repetitions;
            }
        }

        return $best;
    }

    /**
     * Whether the lines that start at the position are repeated once more
     * after the given number of repetitions.
     *
     * @param list<string> $lines
     * @param positive-int $length
     * @param positive-int $repetitions
     */
    private static function isRepeated(array $lines, int $position, int $length, int $repetitions): bool
    {
        $offset = $position + $length * $repetitions;

        if ($offset + $length > count($lines)) {
            return false;
        }

        for ($i = 0; $i < $length; $i++) {
            if (($lines[$position + $i] ?? null) !== ($lines[$offset + $i] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
