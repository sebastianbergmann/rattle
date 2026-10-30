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
use function dirname;
use function implode;
use function sprintf;
use function str_starts_with;
use Throwable;

/**
 * Determines where a throwable was raised and renders stack traces, leaving
 * out rattle's own code.
 *
 * The location of a throwable is its own file and line, unless these lie in
 * rattle's own code. Then it is the first frame of its stack trace that does
 * not. This is how PHPUnit determines the location of a throwable with its
 * own code left out, so that the failure signatures of both tools match.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Location
{
    /**
     * @return non-empty-string
     */
    public static function of(Throwable $throwable): string
    {
        foreach (self::framesOf($throwable) as $frame) {
            if (!self::isRattle($frame['file'])) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }

        return $throwable->getFile() . ':' . $throwable->getLine();
    }

    public static function traceOf(Throwable $throwable): string
    {
        $frames = $throwable->getTrace();

        array_unshift($frames, ['file' => $throwable->getFile(), 'line' => $throwable->getLine()]);

        return self::render($frames);
    }

    /**
     * @param list<array{file?: string, line?: int, class?: class-string, type?: string, function?: string, ...}> $frames
     */
    public static function render(array $frames): string
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

            $lines[] = sprintf('%s:%d%s', $frame['file'], $frame['line'] ?? 0, $function);
        }

        return implode("\n", $lines);
    }

    public static function isRattle(string $file): bool
    {
        return str_starts_with($file, dirname(__DIR__) . '/') ||
               $file === dirname(__DIR__, 2) . '/rattle';
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private static function framesOf(Throwable $throwable): array
    {
        $frames = [['file' => $throwable->getFile(), 'line' => $throwable->getLine()]];

        foreach ($throwable->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return $frames;
    }
}
