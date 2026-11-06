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
use const PHP_BINARY;
use function array_column;
use function array_keys;
use function array_merge;
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function md5_file;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function sort;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzes several fixture targets with planted defects together through the
 * rattle binary, with one worker and a fixed seed, so that every run executes
 * the same inputs.
 */
#[CoversNothing]
#[Large]
final class TargetsTest extends TestCase
{
    private const string ROOT     = __DIR__ . '/../..';
    private const string FIXTURES = 'tests/fixtures/targets';

    /**
     * The fixtures with planted defects, by the names of their targets, with
     * the description of their defects.
     *
     * The fixture of a fatal error for exhausted memory is left out: it needs
     * a memory limit, which would apply to every target of the run, and under
     * a memory limit, whether the input of the fixture of unbounded recursion
     * reaches the limit of the call stack or exhausts the memory first depends
     * on how much memory its worker holds, so that its minimal input may not
     * fail in the same way again.
     */
    private const array DEFECTS = [
        'csv'             => ['csv', 'Warning at %s/csv/src/IntegerColumn.php:46'],
        'magic-header'    => ['magic', 'DivisionByZeroError at %s/magic/src/ImageDecoder.php:37'],
        'endless-loop'    => ['loop', 'exceeding the time limit'],
        'recursion'       => ['recursion', 'Error at %s/recursion/src/TemplateExpander.php:54'],
        'template-loader' => ['traversal', 'SebastianBergmann\\Rattle\\PathTraversal at %s/traversal/src/TemplateLoader.php:30'],
        'thumbnailer'     => ['command', 'SebastianBergmann\\Rattle\\CommandInjection at %s/command/src/Thumbnailer.php:56'],
        'session-decoder' => ['deserialization', 'SebastianBergmann\\Rattle\\UnsafeDeserialization at %s/deserialization/src/SessionDecoder.php:26'],
        'calculator'      => ['evaluation', 'SebastianBergmann\\Rattle\\CodeInjection at %s/evaluation/src/Calculator.php:20'],
        'exit'            => ['exit', 'Process exited with status 0'],
    ];
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-end-to-end-' . uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testFindsThePlantedDefectsOfTheFixturesThatAreFuzzedTogetherAndReproducesEachWithItsOwnTargetFile(): void
    {
        [$exit, $output] = $this->fuzz(
            array_keys(self::DEFECTS),
            ['--seed', '5', '--max-executions', '20000', '--stop-on-finding', '--max-time', '5m', '--sarif', $this->directory . '/findings.sarif'],
        );

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Target:   9 fuzz targets (csv, magic-header, endless-loop, recursion, template-loader, thumbnailer, session-decoder, calculator, exit)', $output);
        $this->assertStringContainsString('9 new findings', $output);
        $this->assertStringContainsString('targets: 0 of 9', $output);
        $this->assertMatchesRegularExpression('/^Target +Executions +Features +Corpus +Findings +All runs +Last new feature$/m', $output);

        foreach (self::DEFECTS as $name => [$fixture, $description]) {
            $description = sprintf($description, self::FIXTURES);
            $findings    = glob($this->directory . '/findings/' . $name . '/*', GLOB_ONLYDIR);

            $this->assertIsArray($findings);
            $this->assertCount(1, $findings, $name);
            $this->assertStringContainsString('Finding: ' . $description . "\n         " . $name . "\n", $output);

            $finding = $findings[0];
            $readme  = (string) file_get_contents($finding . '/README.txt');

            // the minimal input failed in the same way when it was replayed
            $metadata = json_decode((string) file_get_contents($finding . '/finding.json'), true);

            $this->assertIsArray($metadata);
            $this->assertTrue($metadata['minimized'] ?? null, $name);

            // the finding is reproduced with the target file of its target only
            $this->assertStringContainsString(' ' . self::FIXTURES . '/' . $fixture . '/target.php ', $readme);
            $this->assertStringNotContainsString('target.php ' . self::FIXTURES, $readme);

            [$exit, $reproduction] = $this->reproduce($finding);

            $this->assertSame(1, $exit, $reproduction);
            $this->assertStringContainsString('Failure: ' . $description, $reproduction);
        }

        // the findings in SARIF name the targets whose findings they are
        $targets = array_column(SarifResults::inFile($this->directory . '/findings.sarif'), 'target');

        sort($targets);

        $expected = array_keys(self::DEFECTS);

        sort($expected);

        $this->assertSame($expected, $targets);
    }

    public function testTwoRunsWithTheSameSeedAndOneJobExecuteTheSameInputs(): void
    {
        $targets = ['csv', 'magic-header', 'template-loader'];

        $first  = $this->fuzz($targets, ['--seed', '42', '--max-executions', '2000', '--cache-directory', $this->directory . '/first-cache', '--findings-directory', $this->directory . '/first-findings']);
        $second = $this->fuzz($targets, ['--seed', '42', '--max-executions', '2000', '--cache-directory', $this->directory . '/second-cache', '--findings-directory', $this->directory . '/second-findings']);
        $this->fuzz($targets, ['--seed', '43', '--max-executions', '2000', '--cache-directory', $this->directory . '/other-cache', '--findings-directory', $this->directory . '/other-findings']);

        $this->assertNotSame([], $this->table($first[1]));
        $this->assertSame($this->table($first[1]), $this->table($second[1]));
        $this->assertSame($this->filesIn($this->directory . '/first-cache'), $this->filesIn($this->directory . '/second-cache'));
        $this->assertSame($this->filesIn($this->directory . '/first-findings'), $this->filesIn($this->directory . '/second-findings'));
        $this->assertNotSame($this->filesIn($this->directory . '/first-cache'), $this->filesIn($this->directory . '/other-cache'));
    }

    public function testKeepsWhatTheRunsLearnedAboutTheTargetsForTheNextRunAndShowsTheirStatus(): void
    {
        $targets = ['csv', 'magic-header'];

        [$exit, $output] = $this->fuzz($targets, ['--seed', '1', '--max-executions', '500']);

        $this->assertNotSame(2, $exit, $output);
        $this->assertStringContainsString('(0 stored inputs)', $output);

        [$exit, $output] = $this->fuzz($targets, ['--seed', '2', '--max-executions', '500']);

        $this->assertNotSame(2, $exit, $output);

        // the corpora of the first run are replayed first
        $this->assertMatchesRegularExpression('/\([1-9][0-9]* stored inputs?\)/', $output);

        foreach ($targets as $target) {
            $history = json_decode((string) file_get_contents($this->directory . '/cache/' . $target . '/statistics.json'), true);

            $this->assertIsArray($history);
            $this->assertSame($target, $history['target'] ?? null);
            $this->assertSame(2, $history['runs'] ?? null);
            $this->assertSame(1000, $history['executions'] ?? null);
            $this->assertGreaterThan(0, $history['decayedExecutions'] ?? null);
        }

        $status = json_decode((string) file_get_contents($this->directory . '/cache/status.json'), true);

        $this->assertIsArray($status);
        $this->assertIsArray($status['properties'] ?? null);
        $this->assertSame($targets, array_column($status['properties'], 'property'));

        // "rattle status" shows what the runs found out without fuzzing
        [$exit, $output] = $this->rattle(['status', '--cache-directory', $this->directory . '/cache']);

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/^Target +Runs +Executions +Time +Features +Findings +Last new feature$/m', $output);
        $this->assertMatchesRegularExpression('/^csv +2 +1,000 /m', $output);
        $this->assertMatchesRegularExpression('/^magic-header +2 +1,000 /m', $output);

        // ... also in JSON, without anything else
        [$exit, $output] = $this->rattle(['status', '--cache-directory', $this->directory . '/cache', '--json']);

        $this->assertSame(0, $exit, $output);

        $json = json_decode($output, true);

        $this->assertIsArray($json);
        $this->assertIsArray($json['properties'] ?? null);
        $this->assertSame($targets, array_column($json['properties'], 'property'));

        // ... and as a dashboard
        [$exit, $output] = $this->rattle(['status', '--cache-directory', $this->directory . '/cache', '--html', $this->directory . '/status.html']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('<h2>magic-header</h2>', (string) file_get_contents($this->directory . '/status.html'));
    }

    public function testFuzzesTheTargetFilesOfADirectoryWithoutTheBootstrapFile(): void
    {
        $directory = $this->targetDirectory(['csv' => 'csv', 'magic' => 'magic']);

        [$exit, $output] = $this->rattle(
            [
                'fuzz', $directory . '/', '--jobs', '1', '--seed', '1', '--max-executions', '200',
                '--bootstrap', $directory . '/bootstrap.php', '--source', self::FIXTURES . '/csv/src', '--source', self::FIXTURES . '/magic/src',
                '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings',
            ],
        );

        $this->assertNotSame(2, $exit, $output);
        $this->assertStringContainsString('Target:   2 fuzz targets (csv, magic-header)', $output);
        $this->assertDirectoryExists($this->directory . '/cache/csv/corpus');
        $this->assertDirectoryExists($this->directory . '/cache/magic-header/corpus');
    }

    public function testFuzzesADirectoryWithOneTargetFileAsThatTargetFile(): void
    {
        $directory = $this->targetDirectory(['csv' => 'csv']);

        [$exit, $output] = $this->rattle(
            [
                'fuzz', $directory, '--jobs', '1', '--seed', '1', '--max-executions', '200',
                '--bootstrap', $directory . '/bootstrap.php', '--source', self::FIXTURES . '/csv/src',
                '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings',
            ],
        );

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Target:   csv (bytes, at most 64)', $output);
        $this->assertStringNotContainsString('fuzz targets', $output);
        $this->assertStringNotContainsString('targets: ', $output);
    }

    public function testRegressFailsWhileAFindingOfAnyTargetStillFailsAndPassesOnceAllAreFixed(): void
    {
        $project = $this->directory . '/project';

        mkdir($project . '/magic/src', 0o777, true);
        mkdir($project . '/exit/src', 0o777, true);

        foreach (['bootstrap.php', 'magic/target.php', 'magic/src/ImageDecoder.php', 'magic/src/ParseException.php', 'exit/target.php', 'exit/src/CommandLine.php', 'exit/src/UsageException.php'] as $file) {
            file_put_contents($project . '/' . $file, (string) file_get_contents(self::ROOT . '/' . self::FIXTURES . '/' . $file));
        }

        $targets = [$project . '/magic/target.php', $project . '/exit/target.php'];
        $options = ['--bootstrap', $project . '/bootstrap.php', '--source', $project . '/magic/src', '--source', $project . '/exit/src', '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'];

        [$exit, $output] = $this->rattle(['fuzz', ...$targets, ...$options, '--jobs', '1', '--seed', '1', '--max-executions', '10000', '--stop-on-finding']);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('2 new findings', $output);

        [$exit, $output] = $this->rattle(['regress', ...$targets, ...$options, '--sarif', $this->directory . '/regress.sarif']);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('DivisionByZeroError at ' . realpath($project) . '/magic/src/ImageDecoder.php:37', $output);
        $this->assertStringContainsString('Process exited with status 0', $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs of 2 targets, 2 failed\./', $output);
        $this->assertSame(['exit', 'magic-header'], $this->sorted(array_column(SarifResults::inFile($this->directory . '/regress.sarif'), 'target')));

        // the defect of the image decoder is fixed: a scale of 0 is 1
        file_put_contents(
            $project . '/magic/src/ImageDecoder.php',
            str_replace('intdiv(1024, ord($data[4]))', 'intdiv(1024, max(1, ord($data[4])))', (string) file_get_contents($project . '/magic/src/ImageDecoder.php')),
        );

        [$exit, $output] = $this->rattle(['regress', ...$targets, ...$options]);

        $this->assertSame(1, $exit, $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs of 2 targets, 1 failed\./', $output);

        // ... and so is the defect of the command line: -h no longer exits
        file_put_contents(
            $project . '/exit/src/CommandLine.php',
            str_replace('exit(0);', "return ['options' => ['h'], 'arguments' => \$arguments];", (string) file_get_contents($project . '/exit/src/CommandLine.php')),
        );

        [$exit, $output] = $this->rattle(['regress', $project, ...$options]);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('contains no target files', $output);

        [$exit, $output] = $this->rattle(['regress', ...$targets, ...$options]);

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs of 2 targets, 0 failed\./', $output);
    }

    public function testReportsTargetFilesThatCannotBeFuzzedTogetherWithExitCodeTwo(): void
    {
        $directory = $this->targetDirectory(['csv' => 'csv', 'other' => 'csv']);

        [$exit, $output] = $this->rattle(['fuzz', $directory . '/csv.php', $directory . '/other.php', '--bootstrap', $directory . '/bootstrap.php']);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString(sprintf('The target files "%s/csv.php" and "%s/other.php" both return a target named "csv"', $directory, $directory), $output);

        [$exit, $output] = $this->rattle(['regress', $directory . '/csv.php', $directory . '/other.php', '--bootstrap', $directory . '/bootstrap.php', '--cache-directory', $this->directory . '/cache']);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString(sprintf('The target files "%s/csv.php" and "%s/other.php" both return a target named "csv"', $directory, $directory), $output);

        [$exit, $output] = $this->rattle(['fuzz', self::FIXTURES . '/csv/target.php', self::FIXTURES . '/magic/target.php', '--corpus', $this->directory]);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('The options --corpus, --seeds, --log-inputs, and --log-coverage can only be used when one target is fuzzed', $output);

        [$exit, $output] = $this->rattle(['fuzz', 'tests/fixtures/engine/seeds']);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('contains no target files', $output);
    }

    /**
     * @param list<non-empty-string> $targets the names of the targets of the fixtures
     * @param list<string>           $options
     *
     * @return array{0: int, 1: string}
     */
    private function fuzz(array $targets, array $options): array
    {
        $arguments = ['fuzz'];

        foreach ($targets as $target) {
            $fixture = self::DEFECTS[$target][0] ?? $target;

            $arguments[] = self::FIXTURES . '/' . $fixture . '/target.php';
            $arguments[] = '--source';
            $arguments[] = self::FIXTURES . '/' . $fixture . '/src';
        }

        return $this->rattle(
            array_merge(
                $arguments,
                ['--jobs', '1', '--bootstrap', self::FIXTURES . '/bootstrap.php'],
                ['--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'],
                $options,
            ),
        );
    }

    /**
     * A directory with a target file for each of the fixtures, by the names
     * of the files, which returns the target of the fixture, and with a
     * bootstrap file.
     *
     * @param array<non-empty-string, non-empty-string> $fixtures
     */
    private function targetDirectory(array $fixtures): string
    {
        $directory = $this->directory . '/fuzz';
        $root      = (string) realpath(self::ROOT);

        mkdir($directory);

        file_put_contents($directory . '/bootstrap.php', sprintf("<?php require %s;\n", var_export($root . '/' . self::FIXTURES . '/bootstrap.php', true)));

        foreach ($fixtures as $file => $fixture) {
            file_put_contents($directory . '/' . $file . '.php', sprintf("<?php return require %s;\n", var_export($root . '/' . self::FIXTURES . '/' . $fixture . '/target.php', true)));
        }

        return $directory;
    }

    /**
     * The rows of the table that shows how fuzzing went for every target,
     * without the columns that depend on earlier runs and on time.
     *
     * @return list<string>
     */
    private function table(string $output): array
    {
        preg_match_all('/^([a-z0-9-]+ +[0-9,]+ +[0-9,]+ +[0-9,]+ +[0-9,]+) /m', $output, $matches);

        return $matches[1];
    }

    /**
     * The files below a directory, with hashes of their contents.
     *
     * @return array<string, string>
     */
    private function filesIn(string $directory, string $prefix = ''): array
    {
        $files   = [];
        $entries = scandir($directory);

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $files = array_merge($files, $this->filesIn($path, $prefix . $entry . '/'));

                continue;
            }

            // the times in the history, in the status, and in the metadata
            // of a finding differ, and so does the findings directory in the
            // command that reproduces a finding
            if (!in_array($entry, ['statistics.json', 'status.json', 'finding.json', 'README.txt'], true)) {
                $files[$prefix . $entry] = (string) md5_file($path);
            }
        }

        return $files;
    }

    /**
     * @param list<?string> $values
     *
     * @return list<?string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function rattle(array $arguments): array
    {
        return $this->execute([PHP_BINARY, self::ROOT . '/rattle', ...$arguments]);
    }

    /**
     * Executes the command that the README.txt of a finding shows with the
     * shell, as it is copied from there.
     *
     * @return array{0: int, 1: string}
     */
    private function reproduce(string $finding): array
    {
        if (preg_match('/^    rattle (.+)$/m', (string) file_get_contents($finding . '/README.txt'), $matches) !== 1) {
            $this->fail('README.txt does not show the command that reproduces the finding');
        }

        return $this->execute(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/rattle') . ' ' . $matches[1]);
    }

    /**
     * @param list<string>|string $command
     *
     * @return array{0: int, 1: string}
     */
    private function execute(array|string $command): array
    {
        $pipes   = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;

        $this->assertIsResource($process);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        // the standard error stream is read after the standard output stream,
        // which works as long as the workers do not write much to it
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
