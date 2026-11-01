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

use const PHP_BINARY;
use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function getenv;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function version_compare;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Compares the outcomes of the inputs of a fuzz target on real binaries of
 * other versions of PHP, which the environment variable RATTLE_PHP_BINARIES
 * names, separated by colons. The job of CI that installs several versions
 * of PHP sets it; elsewhere, the test is skipped.
 */
#[CoversNothing]
#[Large]
final class VersionsTest extends TestCase
{
    private const string ROOT     = __DIR__ . '/../..';
    private const string FIXTURES = __DIR__ . '/../fixtures/targets';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-versions-' . uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testComparesTheOutcomesOfInputsOnRealBinariesOfOtherVersionsOfPhp(): void
    {
        $paths = array_values(array_filter(explode(':', (string) getenv('RATTLE_PHP_BINARIES')), static fn (string $path): bool => $path !== ''));

        if ($paths === []) {
            $this->markTestSkipped('RATTLE_PHP_BINARIES names no PHP binaries to compare with');
        }

        $binaries = array_map(PhpBinary::at(...), $paths);
        $labels   = DifferentialExecutor::labelsOf([PhpBinary::current(), ...$binaries]);
        $outcomes = [];

        // the fixture rejects strings on PHP 8.6 and later, including its
        // development versions and release candidates
        foreach ([PhpBinary::current(), ...$binaries] as $index => $binary) {
            $outcomes[] = sprintf(
                '%s: %s',
                $labels[$index] ?? '',
                version_compare($binary->version(), '8.6.0-dev', '<') ? 'passes' : 'UnexpectedValueException at tests/fixtures/targets/phpversion/src/MinorVersion.php:30',
            );
        }

        $options = [];

        foreach ($paths as $path) {
            $options[] = '--php';
            $options[] = $path;
        }

        [$exit, $output] = $this->rattle(
            array_merge(
                ['fuzz', self::FIXTURES . '/phpversion/target.php', '--bootstrap', self::FIXTURES . '/bootstrap.php', '--source', self::FIXTURES . '/phpversion/src'],
                ['--jobs', '1', '--seed', '1', '--max-executions', '500', '--stop-on-finding'],
                ['--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'],
                $options,
            ),
        );

        $this->assertStringContainsString(sprintf('Versions: %s, which the corpus and the findings are compared on', implode(', ', $labels)), $output);

        if (count(array_unique(array_map(static fn (string $outcome): string => explode(': ', $outcome, 2)[1] ?? '', $outcomes))) === 1) {
            $this->assertSame(0, $exit, $output);
            $this->assertStringNotContainsString('Difference between PHP versions', $output);

            return;
        }

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Finding: Difference between PHP versions: ' . implode('; ', $outcomes), $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function rattle(array $arguments): array
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, self::ROOT . '/rattle', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;

        $this->assertIsResource($process);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $output = (string) stream_get_contents($stdout);
        $output .= (string) stream_get_contents($stderr);

        return [proc_close($process), $output];
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
