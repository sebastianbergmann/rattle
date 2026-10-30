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

use const PHP_BINARY;
use function escapeshellarg;
use function json_decode;
use function shell_exec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * Runs the functions of a fixture with and without instrumentation, in
 * separate processes, and compares what they return or throw, including the
 * file and line of every throwable and of its stack frames, and the issues
 * they trigger.
 */
#[CoversClass(Instrumentor::class)]
#[CoversClass(BlockFinder::class)]
#[CoversClass(BlockStart::class)]
#[CoversClass(IncludeHook::class)]
#[Medium]
final class LineNumberFidelityTest extends TestCase
{
    public function testThrowablesAndIssuesInInstrumentedCodeReportTheSameFileAndLineAsWithoutInstrumentation(): void
    {
        $plain        = $this->runFixture('plain');
        $instrumented = $this->runFixture('instrumented');

        $this->assertSame(0, $plain['edges']);
        $this->assertGreaterThan(100, $instrumented['edges']);
        $this->assertSame($plain['results'], $instrumented['results']);
        $this->assertCount(34, $plain['results']);
    }

    /**
     * @return array{results: array<string, mixed>, edges: int}
     */
    private function runFixture(string $mode): array
    {
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' .
            escapeshellarg(__DIR__ . '/../../fixtures/instrumentation/run.php') . ' ' .
            escapeshellarg($mode),
        );

        $this->assertIsString($output);

        $result = json_decode($output, true);

        $this->assertIsArray($result);

        /** @var array{results: array<string, mixed>, edges: int} $result */
        return $result;
    }
}
