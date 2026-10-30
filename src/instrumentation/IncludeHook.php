<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use function dirname;
use function file_get_contents;
use function realpath;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * Instruments the files below the source directories while they are
 * included (see FileStreamWrapper). Files in a vendor directory, the target
 * file, and rattle's own files are never instrumented. Files in a PHAR are
 * not included through file:// and are not instrumented either.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class IncludeHook
{
    private readonly Instrumentor $instrumentor;

    /**
     * @var list<non-empty-string>
     */
    private readonly array $sources;

    /**
     * @var list<non-empty-string>
     */
    private readonly array $excluded;

    /**
     * @var array<string, string>
     */
    private array $failures = [];

    /**
     * @param list<string> $sources  the directories and files to instrument
     * @param list<string> $excluded the directories and files not to instrument
     */
    public function __construct(Instrumentor $instrumentor, array $sources, array $excluded)
    {
        $this->instrumentor = $instrumentor;
        $this->sources      = self::realpaths($sources);
        $this->excluded     = self::realpaths([...$excluded, dirname(__DIR__)]);
    }

    public function register(): void
    {
        FileStreamWrapper::register($this->intercept(...));
    }

    public function unregister(): void
    {
        FileStreamWrapper::unregister();
    }

    public function shouldInstrument(string $path): bool
    {
        if (str_contains($path, '/vendor/')) {
            return false;
        }

        foreach ($this->excluded as $excluded) {
            if (self::contains($excluded, $path)) {
                return false;
            }
        }

        foreach ($this->sources as $source) {
            if (self::contains($source, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The files that could not be instrumented, and why, by their path. These
     * files are included without instrumentation.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    private function intercept(string $path): ?string
    {
        if (!$this->shouldInstrument($path)) {
            return null;
        }

        $code = file_get_contents($path);

        if ($code === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        try {
            return $this->instrumentor->instrument($code, $path);
        } catch (InstrumentationFailedException $e) {
            $this->failures[$path] = $e->getMessage();

            return null;
        }
    }

    /**
     * @param list<string> $paths
     *
     * @return list<non-empty-string>
     */
    private static function realpaths(array $paths): array
    {
        $realpaths = [];

        foreach ($paths as $path) {
            $realpath = realpath($path);

            if ($realpath !== false) {
                $realpaths[] = $realpath;
            }
        }

        return $realpaths;
    }

    private static function contains(string $directoryOrFile, string $path): bool
    {
        return $path === $directoryOrFile ||
               str_starts_with($path, rtrim($directoryOrFile, '/') . '/');
    }
}
