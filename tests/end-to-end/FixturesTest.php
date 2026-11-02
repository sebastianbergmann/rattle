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
use function array_merge;
use function count;
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\Decoder;

/**
 * Fuzzes the fixture targets with planted defects through the rattle binary,
 * with one worker and a fixed seed, so that every run executes the same
 * inputs. The execution budget of each fixture is about twice the number of
 * executions after which its defect was found when the budget was fixed.
 */
#[CoversNothing]
#[Large]
final class FixturesTest extends TestCase
{
    private const string ROOT     = __DIR__ . '/../..';
    private const string FIXTURES = __DIR__ . '/../fixtures/targets';
    private string $directory;

    /**
     * @return array<string, array{0: string, 1: int, 2: positive-int, 3: string, 4: ?string, 5: list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'a warning for a digit string that is too long for an int' => [
                'csv',
                5,
                8000,
                'Warning at %s/csv/src/IntegerColumn.php:46',
                '10000000000000000000',
                [],
            ],
            'an uncaught exception behind a magic header that is found with a dictionary' => [
                'magic',
                2,
                1000,
                'DivisionByZeroError at %s/magic/src/ImageDecoder.php:37',
                "RATL\x00",
                ['--dictionary', self::FIXTURES . '/magic/dictionary.txt'],
            ],
            'an uncaught exception behind a magic header that is found with a token from a comparison' => [
                'magic',
                2,
                1000,
                'DivisionByZeroError at %s/magic/src/ImageDecoder.php:37',
                "RATL\x00",
                [],
            ],
            'a timeout for an endless loop' => [
                'loop',
                2,
                1000,
                'exceeding the time limit',
                '\\',
                [],
            ],
            'a fatal error for exhausted memory' => [
                'memory',
                1,
                500,
                'Fatal error at %s/memory/src/RunLengthDecoder.php:35',
                null,
                ['--memory-limit', '64M'],
            ],
            'a fatal error for exhausted memory, with OPcache and its JIT' => [
                'memory',
                1,
                500,
                'Fatal error at %s/memory/src/RunLengthDecoder.php:35',
                null,
                ['--memory-limit', '64M', '--opcache'],
            ],
            'the error for the call stack limit for unbounded recursion' => [
                'recursion',
                1,
                10000,
                'Error at %s/recursion/src/TemplateExpander.php:54',
                'a=$a',
                [],
            ],
            'a path traversal that reaches the sentinel of the file system' => [
                'traversal',
                6,
                1200,
                'SebastianBergmann\\Rattle\\PathTraversal at %s/traversal/src/TemplateLoader.php:30',
                null,
                [],
            ],
            'a command injection that runs the sentinel command' => [
                'command',
                9,
                1000,
                'SebastianBergmann\\Rattle\\CommandInjection at %s/command/src/Thumbnailer.php:56',
                null,
                [],
            ],
            'an unsafe deserialization that creates an object of the sentinel class' => [
                'deserialization',
                1,
                1200,
                'SebastianBergmann\\Rattle\\UnsafeDeserialization at %s/deserialization/src/SessionDecoder.php:26',
                null,
                [],
            ],
            'a code injection that evaluates code which creates an object of the sentinel class' => [
                'evaluation',
                3,
                2000,
                'SebastianBergmann\\Rattle\\CodeInjection at %s/evaluation/src/Calculator.php:20',
                null,
                [],
            ],
            'a target that calls exit()' => [
                'exit',
                1,
                8000,
                'Process exited with status 0',
                '-h',
                [],
            ],
        ];
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-end-to-end-' . uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    /**
     * @param positive-int $budget
     * @param list<string> $options
     */
    #[DataProvider('fixtures')]
    public function testFindsMinimizesAndReproducesThePlantedDefect(string $fixture, int $seed, int $budget, string $description, ?string $minimal, array $options): void
    {
        // rattle runs in the root directory of the project, and the paths in
        // locations are relative to it
        $description = sprintf($description, 'tests/fixtures/targets');

        [$exit, $output] = $this->fuzz($fixture, array_merge(['--seed', (string) $seed, '--max-executions', (string) $budget, '--stop-on-finding'], $options));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Finding: ' . $description, $output);
        $this->assertStringContainsString('1 new finding', $output);

        $findings = glob($this->directory . '/findings/*/*', GLOB_ONLYDIR);

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings);

        $finding = $findings[0] ?? '';

        foreach (['input', 'original', 'input.bin', 'finding.json', 'README.txt'] as $file) {
            $this->assertFileExists($finding . '/' . $file);
        }

        // the minimal input failed in the same way when it was replayed
        $metadata = json_decode((string) file_get_contents($finding . '/finding.json'), true);

        $this->assertIsArray($metadata);
        $this->assertTrue($metadata['minimized'] ?? null);

        // the inputs are stored in the format of choice sequences and are read
        // with the decoder of sebastian/choice-sequence
        $input    = (new Decoder)->decode((string) file_get_contents($finding . '/input'));
        $original = (new Decoder)->decode((string) file_get_contents($finding . '/original'));

        $this->assertLessThanOrEqual(count($original->choices()), count($input->choices()));

        if ($minimal !== null) {
            $this->assertSame($minimal, file_get_contents($finding . '/input.bin'));
        }

        // the minimal input reproduces the finding with the command that
        // README.txt shows
        [$exit, $output] = $this->reproduce($finding);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Failure: ' . $description, $output);

        // ... and so does the raw input of a bytes target
        [$exit, $output] = $this->rattle(array_merge(['run', '--raw', self::FIXTURES . '/' . $fixture . '/target.php', $finding . '/input.bin'], $this->executionOptions($fixture), $this->runOptions($options)));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Failure: ' . $description, $output);
    }

    public function testWritesTheTokensThatWereFoundInComparisonsToADictionaryFile(): void
    {
        $dictionary = $this->directory . '/cache/magic-header/dictionary.txt';

        [$exit, $output] = $this->fuzz('magic', ['--seed', '2', '--max-executions', '10']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('1 token that was found in comparisons was written to ' . $dictionary, $output);
        $this->assertStringNotContainsString('No file below the source directories was instrumented', $output);
        $this->assertStringEqualsFile($dictionary, "# the constant operands of comparisons that rattle found in the code that it instrumented\n\"RATL\"\n");

        // the dictionary file can be passed with --dictionary, and is written
        // again with the token that it has
        [$exit, $output] = $this->fuzz('magic', ['--seed', '2', '--max-executions', '10', '--dictionary', $dictionary]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('tokens: 1,', $output);
        $this->assertFileExists($dictionary);
    }

    public function testRemovesTheDictionaryFileOfAnEarlierRunWhenNoTokensWereFound(): void
    {
        $dictionary = $this->directory . '/cache/csv/dictionary.txt';

        mkdir(dirname($dictionary), 0o777, true);
        file_put_contents($dictionary, "\"stale\"\n");

        [$exit, $output] = $this->fuzz('csv', ['--seed', '2', '--max-executions', '10']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('found in comparisons', $output);
        $this->assertFileDoesNotExist($dictionary);
    }

    public function testWarnsWhenNoFileBelowTheSourceDirectoriesWasInstrumented(): void
    {
        // the code of the csv target is not below the source directory of
        // another fixture
        [$exit, $output] = $this->rattle(
            [
                'fuzz', self::FIXTURES . '/csv/target.php', '--jobs', '1', '--seed', '1', '--max-executions', '1000',
                '--bootstrap', self::FIXTURES . '/bootstrap.php', '--source', self::FIXTURES . '/magic/src',
                '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings',
            ],
        );

        $this->assertSame(0, $exit, $output);
        $this->assertSame(1, substr_count($output, 'Warning: No file below the source directories was instrumented, so no coverage guides fuzzing.'), $output);
    }

    public function testTwoRunsWithTheSameSeedAndOneJobExecuteTheSameInputs(): void
    {
        $this->fuzz('csv', ['--seed', '42', '--max-executions', '3000', '--log-inputs', $this->directory . '/first.log', '--cache-directory', $this->directory . '/first-cache', '--findings-directory', $this->directory . '/first-findings']);
        $this->fuzz('csv', ['--seed', '42', '--max-executions', '3000', '--log-inputs', $this->directory . '/second.log', '--cache-directory', $this->directory . '/second-cache', '--findings-directory', $this->directory . '/second-findings']);
        $this->fuzz('csv', ['--seed', '43', '--max-executions', '3000', '--log-inputs', $this->directory . '/other.log', '--cache-directory', $this->directory . '/other-cache', '--findings-directory', $this->directory . '/other-findings']);

        $first = (string) file_get_contents($this->directory . '/first.log');

        $this->assertSame(3000, substr_count($first, "\n"));
        $this->assertSame($first, file_get_contents($this->directory . '/second.log'));
        $this->assertNotSame($first, file_get_contents($this->directory . '/other.log'));
    }

    public function testAWorkerThatDiesIsReplacedAndFuzzingContinues(): void
    {
        [$exit, $output] = $this->fuzz('exit', ['--seed', '1', '--max-executions', '20000']);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('executions: 20,000', $output);
        $this->assertStringContainsString('findings: 1', $output);
        $this->assertStringContainsString('1 new finding', $output);
    }

    public function testFindingsAreDeduplicatedAcrossRunsThroughTheFindingsDirectory(): void
    {
        [$exit] = $this->fuzz('exit', ['--seed', '1', '--max-executions', '8000', '--stop-on-finding']);

        $this->assertSame(1, $exit);

        [$exit, $output] = $this->fuzz('exit', ['--seed', '3', '--max-executions', '8000']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('(1 known)', $output);
        $this->assertStringContainsString('0 new findings', $output);
        $this->assertDoesNotMatchRegularExpression('/^Finding:/m', $output);

        // the corpus of the first run was stored and is replayed first
        $this->assertMatchesRegularExpression('/\([1-9][0-9]* stored inputs?\)/', $output);
    }

    public function testRegressFailsWhileAFindingStillFailsAndPassesOnceItIsFixed(): void
    {
        $project = $this->directory . '/project';

        mkdir($project . '/exit/src', 0o777, true);

        foreach (['bootstrap.php', 'exit/target.php', 'exit/src/CommandLine.php', 'exit/src/UsageException.php'] as $file) {
            file_put_contents($project . '/' . $file, (string) file_get_contents(self::FIXTURES . '/' . $file));
        }

        $options = ['--bootstrap', $project . '/bootstrap.php', '--source', $project . '/exit/src', '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'];

        [$exit] = $this->rattle(array_merge(['fuzz', $project . '/exit/target.php', '--jobs', '1', '--seed', '1', '--max-executions', '8000', '--stop-on-finding'], $options));

        $this->assertSame(1, $exit);

        [$exit, $output] = $this->rattle(array_merge(['regress', $project . '/exit/target.php', '--sarif', $this->directory . '/regress.sarif'], $options));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Process exited with status 0', $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs?, 1 failed/', $output);
        $this->assertStringContainsString('1 finding was written to ' . $this->directory . '/regress.sarif in SARIF.', $output);
        $this->assertSame(['process-ended'], array_column(SarifResults::inFile($this->directory . '/regress.sarif'), 'ruleId'));

        // the defect is fixed: -h no longer exits
        file_put_contents(
            $project . '/exit/src/CommandLine.php',
            str_replace('exit(0);', "return ['options' => ['h'], 'arguments' => \$arguments];", (string) file_get_contents($project . '/exit/src/CommandLine.php')),
        );

        [$exit, $output] = $this->rattle(array_merge(['regress', $project . '/exit/target.php', '--sarif', $this->directory . '/regress.sarif'], $options));

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs?, 0 failed/', $output);

        // the report without results tells code scanning that the finding
        // is fixed
        $this->assertSame([], SarifResults::inFile($this->directory . '/regress.sarif'));
    }

    public function testFindsAnInputWhoseOutcomeDiffersBetweenVersionsOfPhpAndMinimizesIt(): void
    {
        $other = self::ROOT . '/tests/fixtures/php/php-precision-5';

        [$exit, $output] = $this->fuzz('versions', ['--seed', '1', '--max-executions', '500', '--stop-on-finding', '--php', $other]);

        $first       = PhpBinary::current()->label();
        $description = sprintf('Difference between PHP versions: %s: passes; %s (php-precision-5): LengthException at tests/fixtures/targets/versions/src/Ratio.php:32', $first, $first);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString(sprintf('Versions: %s, %s (php-precision-5), which the corpus and the findings are compared on', $first, $first), $output);
        $this->assertStringContainsString('Finding: ' . $description, $output);

        $findings = glob($this->directory . '/findings/ratio/*', GLOB_ONLYDIR);

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings);

        $finding = $findings[0] ?? '';

        // a string of one character is the smallest that is shown with too
        // few digits on the other version
        $this->assertSame("\x00", file_get_contents($finding . '/input.bin'));
        $this->assertStringContainsString("rattle run --php '" . $other . "' ", (string) file_get_contents($finding . '/README.txt'));

        // "rattle run --php" shows the outcome on every version
        [$exit, $output] = $this->rattle(array_merge(['run', self::FIXTURES . '/versions/target.php', $finding . '/input', '--php', $other], $this->executionOptions('versions')));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString($first . "\nOutcome: passed", $output);
        $this->assertStringContainsString($first . " (php-precision-5)\nOutcome: failed", $output);
        $this->assertStringContainsString($description, $output);

        // on one version, the input of the difference passes
        $options = array_merge($this->executionOptions('versions'), ['--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings']);

        [$exit, $output] = $this->rattle(array_merge(['regress', self::FIXTURES . '/versions/target.php'], $options));

        $this->assertSame(0, $exit, $output);

        // ... while "rattle regress --php" replays it on both versions
        [$exit, $output] = $this->rattle(array_merge(['regress', self::FIXTURES . '/versions/target.php', '--php', $other], $options));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Differs: ' . $finding . "/input\n       " . $description, $output);
        $this->assertStringContainsString('Fails on ' . $first . ' (php-precision-5): ' . $finding . '/input', $output);
    }

    public function testWritesTheFindingsOfARunInSarif(): void
    {
        [$exit, $output] = $this->fuzz('traversal', ['--seed', '6', '--max-executions', '1200', '--stop-on-finding', '--sarif', $this->directory . '/fuzz.sarif']);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('1 finding was written to ' . $this->directory . '/fuzz.sarif in SARIF.', $output);

        $results = SarifResults::inFile($this->directory . '/fuzz.sarif');
        $result  = $results[0] ?? null;

        $this->assertCount(1, $results);
        $this->assertNotNull($result);
        $this->assertSame(
            ['path-traversal', 'error', 'tests/fixtures/targets/traversal/src/TemplateLoader.php', '%SRCROOT%', 30, null],
            [$result['ruleId'], $result['level'], $result['uri'], $result['uriBaseId'], $result['startLine'], $result['property']],
        );
    }

    public function testMinimizesAnInput(): void
    {
        $input = $this->directory . '/input';

        file_put_contents($input, "choice-sequence v1\n" . implode("\n", BytesEncoding::choicesFor('abc def -x -h ghi', 64)) . "\n");

        [$exit, $output] = $this->rattle(array_merge(['minimize', self::FIXTURES . '/exit/target.php', $input], $this->executionOptions('exit')));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Failure: Process exited with status 0', $output);
        $this->assertSame(
            BytesEncoding::choicesFor('-h', 64),
            (new Decoder)->decode((string) file_get_contents($input . '.minimized'))->choices(),
        );

        file_put_contents($input, "choice-sequence v1\n0\n");

        [$exit, $output] = $this->rattle(array_merge(['minimize', self::FIXTURES . '/exit/target.php', $input], $this->executionOptions('exit')));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('The input does not make the target fail', $output);
    }

    public function testRunsAnInputAndShowsTheValuesItDraws(): void
    {
        $input = $this->directory . '/input';

        file_put_contents($input, 'abc');

        [$exit, $output] = $this->rattle(array_merge(['run', '--raw', self::FIXTURES . '/exit/target.php', $input], $this->executionOptions('exit')));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Outcome: passed', $output);
        $this->assertStringContainsString("#1 'abc'", $output);
    }

    public function testRunsTheWorkerProcessesWithOpcacheWhichCachesTheInstrumentedCode(): void
    {
        $input = $this->directory . '/input';

        file_put_contents($input, 'a');

        [$exit, $output] = $this->rattle(array_merge(['run', '--raw', '--opcache', self::FIXTURES . '/opcache/target.php', $input], $this->executionOptions('opcache')));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Outcome: passed', $output);
    }

    public function testReportsInvalidConfigurationWithExitCodeTwo(): void
    {
        [$exit, $output] = $this->rattle(['fuzz', $this->directory . '/does-not-exist.php']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('does not exist', $output);

        [$exit, $output] = $this->fuzz('exit', ['--seeds', $this->directory . '/does-not-exist']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('The seeds directory', $output);

        [$exit, $output] = $this->rattle(array_merge(['fuzz', self::FIXTURES . '/exit/target.php', '--dictionary', self::ROOT . '/tests/fixtures/engine/invalid-dictionary.txt'], $this->executionOptions('exit')));

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Line 2 of the dictionary is not valid', $output);
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: int, 1: string}
     */
    private function fuzz(string $fixture, array $options): array
    {
        return $this->rattle(
            array_merge(
                ['fuzz', self::FIXTURES . '/' . $fixture . '/target.php', '--jobs', '1'],
                $this->executionOptions($fixture),
                ['--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'],
                $options,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function executionOptions(string $fixture): array
    {
        return ['--bootstrap', self::FIXTURES . '/bootstrap.php', '--source', self::FIXTURES . '/' . $fixture . '/src'];
    }

    /**
     * The options of "rattle fuzz" that "rattle run" accepts as well.
     *
     * @param list<string> $options
     *
     * @return list<string>
     */
    private function runOptions(array $options): array
    {
        if (($options[0] ?? null) === '--memory-limit') {
            return $options;
        }

        return [];
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
