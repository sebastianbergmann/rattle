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
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function preg_quote;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzes the properties of a PHPUnit test suite through the rattle binary,
 * with the version of PHPUnit that rattle is developed with.
 */
#[CoversNothing]
#[Large]
final class PhpunitTest extends TestCase
{
    private const string FIXTURE  = 'tests/fixtures/phpunit';
    private const string PROPERTY = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList';
    private const string OTHER    = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testCountsAreNotNegative';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-phpunit-' . uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testFuzzesThePropertiesOfATestSuiteOneAfterAnother(): void
    {
        [$exit, $output] = $this->fuzz([]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Target:   ' . self::PROPERTY . ' (property)', $output);
        $this->assertStringContainsString('Target:   ' . self::OTHER . ' (property)', $output);
        $this->assertStringContainsString('Finding: LogicException at ' . self::FIXTURE . '/src/Classifier.php:38', $output);

        $findings = glob($this->directory . '/findings/classifiertest-testclassifieseverylist-*/*', GLOB_ONLYDIR);

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings);

        $finding = $findings[0] ?? '';

        // the minimal input is the list 7, 3, 9
        $this->assertStringEqualsFile($finding . '/input', "choice-sequence v1\n1\n7\n1\n3\n1\n9\n0\n");
        $this->assertStringContainsString("rattle run --phpunit --configuration '" . self::FIXTURE . "/phpunit.xml' '" . self::PROPERTY . "' ", (string) file_get_contents($finding . '/README.txt'));

        [$exit, $output] = $this->reproduce($finding);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Failure: LogicException at ' . self::FIXTURE . '/src/Classifier.php:38', $output);
        $this->assertStringContainsString('Message: cannot classify', $output);
        $this->assertStringContainsString("Values:\n  \$values = [7, 3, 9];\n", $output);
        $this->assertStringContainsString('"drawnValues": [' . "\n" . '        "$values = [7, 3, 9];"', (string) file_get_contents($finding . '/finding.json'));
    }

    public function testFuzzesOnlyThePropertiesThatMatchTheFilter(): void
    {
        [$exit, $output] = $this->fuzz(['--filter', 'Counts']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString(self::PROPERTY, $output);
        $this->assertStringContainsString('Target:   ' . self::OTHER . ' (property)', $output);
    }

    public function testThePropertiesShareTheTimeOfMaxTime(): void
    {
        [$exit, $output] = $this->rattle(['fuzz', '--phpunit', '--configuration', self::FIXTURE . '/phpunit.xml', '--source', self::FIXTURE . '/src', '--jobs', '1', '--seed', '2', '--max-time', '6s', '--stop-on-finding', '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings']);

        $this->assertSame(1, $exit, $output);

        // the first property gets half of the time and stops at its finding,
        // so the second property gets the rest
        $this->assertStringContainsString('Target:   ' . self::PROPERTY . " (property)\n", $output);
        $this->assertMatchesRegularExpression('/' . preg_quote(self::PROPERTY, '/') . '.*?Time: {5}at most 3s of --max-time\n/s', $output);
        $this->assertMatchesRegularExpression('/' . preg_quote(self::OTHER, '/') . '.*?Time: {5}at most [3-6]s of --max-time\n/s', $output);
    }

    public function testInstrumentsTheSourceOfTheConfigurationAndImportsACorpusForOneProperty(): void
    {
        mkdir($this->directory . '/corpus');

        [$exit, $output] = $this->rattle(['fuzz', '--phpunit', '--configuration', self::FIXTURE . '/phpunit.xml', '--filter', 'Counts', '--corpus', $this->directory . '/corpus', '--jobs', '1', '--max-executions', '10', '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Sources:  ' . realpath(dirname(__DIR__, 2) . '/' . self::FIXTURE . '/src') . "\n", $output);
    }

    public function testImportsACorpusOnlyWhenTheFilterSelectsOneProperty(): void
    {
        mkdir($this->directory . '/corpus');

        [$exit, $output] = $this->fuzz(['--corpus', $this->directory . '/corpus']);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('The options --corpus and --log-inputs can only be used with --phpunit when the filter selects one property', $output);
    }

    public function testRegressFailsWhileAFindingOfAPropertyStillFailsAndPassesOnceItIsFixed(): void
    {
        $project = $this->directory . '/project';

        mkdir($project . '/src', 0o777, true);
        mkdir($project . '/tests');

        foreach (['phpunit.xml', 'bootstrap.php', 'src/Classifier.php', 'tests/ClassifierTest.php'] as $file) {
            file_put_contents($project . '/' . $file, (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::FIXTURE . '/' . $file));
        }

        $options = ['--phpunit', '--configuration', $project . '/phpunit.xml', '--cache-directory', $this->directory . '/cache', '--findings-directory', $this->directory . '/findings'];

        [$exit, $output] = $this->rattle(['fuzz', ...$options, '--jobs', '1', '--seed', '2', '--max-executions', '1000', '--stop-on-finding']);

        $this->assertSame(1, $exit, $output);

        [$exit, $output] = $this->rattle(['regress', ...$options]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('LogicException at ' . realpath($project) . '/src/Classifier.php:38', $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs? of 2 properties, 1 failed\./', $output);

        // the defect is fixed: the list 7, 3, 9 is classified
        file_put_contents(
            $project . '/src/Classifier.php',
            str_replace("throw new LogicException('cannot classify');", "return 'd';", (string) file_get_contents($project . '/src/Classifier.php')),
        );

        [$exit, $output] = $this->rattle(['regress', ...$options]);

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/Replayed [0-9]+ inputs? of 2 properties, 0 failed\./', $output);
    }

    public function testMinimizesAnInputOfAProperty(): void
    {
        $input = $this->directory . '/input';

        // the list 7, 3, 9, 4, 5
        file_put_contents($input, "choice-sequence v1\n1\n7\n1\n3\n1\n9\n1\n4\n1\n5\n0\n");

        [$exit, $output] = $this->rattle(['minimize', '--phpunit', '--configuration', self::FIXTURE . '/phpunit.xml', self::PROPERTY, $input]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Failure: LogicException at ' . self::FIXTURE . '/src/Classifier.php:38', $output);
        $this->assertStringContainsString('Minimized from 11 to 7 choices.', $output);
        $this->assertStringEqualsFile($input . '.minimized', "choice-sequence v1\n1\n7\n1\n3\n1\n9\n0\n");
    }

    public function testReportsATestSuiteWithoutProperties(): void
    {
        [$exit, $output] = $this->fuzz(['--filter', 'DoesNotMatchAnyProperty']);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('The PHPUnit test suite has no properties that match the filter', $output);
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: int, 1: string}
     */
    private function fuzz(array $options): array
    {
        return $this->rattle(
            [
                'fuzz',
                '--phpunit',
                '--configuration',
                self::FIXTURE . '/phpunit.xml',
                '--source',
                self::FIXTURE . '/src',
                '--jobs',
                '1',
                '--seed',
                '2',
                '--max-executions',
                '1000',
                '--stop-on-finding',
                '--cache-directory',
                $this->directory . '/cache',
                '--findings-directory',
                $this->directory . '/findings',
                ...$options,
            ],
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function rattle(array $arguments): array
    {
        return $this->execute([PHP_BINARY, dirname(__DIR__, 2) . '/rattle', ...$arguments]);
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

        return $this->execute(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/rattle') . ' ' . $matches[1]);
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
