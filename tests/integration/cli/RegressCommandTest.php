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

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Encoder;
use SebastianBergmann\Rattle\Instrumentation\SourceResolver;

/**
 * Replays the corpora and the findings of fixture targets, and of the
 * properties of a fixture test suite, with "rattle regress", with workers.
 */
#[CoversClass(RegressCommand::class)]
#[CoversClass(PropertyLister::class)]
#[UsesClass(Application::class)]
#[UsesClass(Arguments::class)]
#[UsesClass(ArgumentsBuilder::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(DifferentialExecutor::class)]
#[UsesClass(Failure::class)]
#[UsesClass(FindingException::class)]
#[UsesClass(FindingStore::class)]
#[UsesClass(Frame::class)]
#[UsesClass(FrameBuffer::class)]
#[UsesClass(Job::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(PhpBinary::class)]
#[UsesClass(PhpunitTestSuite::class)]
#[UsesClass(ProcessExecutor::class)]
#[UsesClass(PropertyDirectory::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(SarifReport::class)]
#[UsesClass(Setup::class)]
#[UsesClass(SourceResolver::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(WorkerConfiguration::class)]
#[UsesClass(WorkerException::class)]
#[UsesClass(WorkerProcess::class)]
#[Large]
final class RegressCommandTest extends TestCase
{
    private const string TARGETS  = 'tests/fixtures/targets';
    private const string PHPUNIT  = 'tests/fixtures/phpunit';
    private const string PROPERTY = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-regress-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testPassesWhileNoInputOfTheCorpusOrTheFindingsFails(): void
    {
        $this->store('magic-header/corpus/passing', BytesEncoding::choicesFor("RATL\x01", 64));
        $this->store('magic-header/corpus/expected', BytesEncoding::choicesFor('GIF8', 64));

        [$exit, $output] = $this->regress($this->fixture('magic'));

        $this->assertSame(0, $exit);
        $this->assertSame("Replayed 2 inputs, 0 failed.\n", $output);
    }

    public function testFailsWhileAnInputOfAFindingStillFailsAndWritesItInSarif(): void
    {
        $this->store('magic-header/corpus/passing', BytesEncoding::choicesFor("RATL\x01", 64));
        $this->store('findings/magic-header/finding/input', BytesEncoding::choicesFor("RATL\x00", 64));

        [$exit, $output] = $this->regress([...$this->fixture('magic'), '--sarif', $this->directory . '/regress.sarif']);

        $this->assertSame(1, $exit);
        $this->assertSame(
            'Fails: ' . $this->directory . '/findings/magic-header/finding/input' . "\n" .
            '       DivisionByZeroError at ' . self::TARGETS . '/magic/src/ImageDecoder.php:37' . "\n\n" .
            "Replayed 2 inputs, 1 failed.\n" .
            '1 finding was written to ' . $this->directory . '/regress.sarif in SARIF.' . "\n",
            $output,
        );
        $this->assertCount(1, SarifResults::inFile($this->directory . '/regress.sarif'));
    }

    public function testFailsForAnInputThatIsNotAValidChoiceSequence(): void
    {
        $this->write('magic-header/corpus/invalid', 'not a choice sequence');

        [$exit, $output] = $this->regress($this->fixture('magic'));

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('Invalid: ' . $this->directory . '/magic-header/corpus/invalid: ', $output);
        $this->assertStringEndsWith("\n\nReplayed 0 inputs, 0 failed.\n", $output);
    }

    public function testReplaysTheInputsOfSeveralTargetsOneAfterTheOther(): void
    {
        $this->store('magic-header/corpus/passing', BytesEncoding::choicesFor("RATL\x01", 64));
        $this->store('findings/exit/finding/input', BytesEncoding::choicesFor('-h', 64));

        [$exit, $output] = $this->regress([self::TARGETS . '/magic/target.php', self::TARGETS . '/exit/target.php', '--bootstrap', self::TARGETS . '/bootstrap.php']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("       Process exited with status 0\n", $output);
        $this->assertStringEndsWith("\n\nReplayed 2 inputs of 2 targets, 1 failed.\n", $output);
    }

    public function testRejectsTwoTargetFilesWhoseTargetsHaveTheSameName(): void
    {
        $worker = 'tests/fixtures/worker';

        [$exit, $output] = $this->regress([$worker . '/target.php', $worker . '/duplicate-target.php', '--bootstrap', $worker . '/bootstrap.php']);

        $this->assertSame(2, $exit);
        $this->assertSame('The target files "' . $worker . '/target.php" and "' . $worker . '/duplicate-target.php" both return a target named "worker"' . "\n", $output);
    }

    public function testReportsATargetFileWhoseWorkerCannotBeStarted(): void
    {
        [$exit, $output] = $this->regress(['tests/fixtures/worker/not-a-target.php', '--bootstrap', 'tests/fixtures/worker/bootstrap.php']);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('The worker could not be started: The target file "tests/fixtures/worker/not-a-target.php" must return an instance of ' . Target::class, $output);
    }

    public function testReplaysTheInputsOnTheVersionsOfPhpOfThePhpBinariesAndFailsForAnInputWhoseOutcomeDiffers(): void
    {
        $this->store('findings/ratio/finding/input', BytesEncoding::choicesFor("\x00", 16));

        [$exit, $output] = $this->regress([...$this->fixture('versions'), '--php', dirname(__DIR__, 2) . '/fixtures/php/php-precision-5']);

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/^Fails on .+ \(php-precision-5\): .+\/findings\/ratio\/finding\/input\n {7}LengthException at /m', $output);
        $this->assertMatchesRegularExpression('/^Differs: .+\/findings\/ratio\/finding\/input\n {7}Difference between PHP versions: /m', $output);
        $this->assertStringEndsWith("\n\nReplayed 1 input, 1 failed.\n", $output);
    }

    public function testRejectsAPhpBinaryOfAVersionOfPhpThatIsTooOld(): void
    {
        [$exit, $output] = $this->regress([...$this->fixture('magic'), '--php', 'tests/fixtures/php/php-8.4']);

        $this->assertSame(2, $exit);
        $this->assertSame('The PHP binary "tests/fixtures/php/php-8.4" is PHP 8.4.10, but rattle needs PHP 8.5 or later' . "\n", $output);
    }

    public function testFailsWhenTheInputsThatFailedCannotBeWrittenInSarif(): void
    {
        [$exit, $output] = $this->regress([...$this->fixture('magic'), '--sarif', '/dev/null/regress.sarif']);

        $this->assertSame(2, $exit);
        $this->assertSame("Replayed 0 inputs, 0 failed.\nCannot write the SARIF report to \"/dev/null/regress.sarif\"\n", $output);
    }

    public function testReplaysTheInputsOfThePropertiesOfAPhpunitTestSuite(): void
    {
        $this->store('findings/' . PropertyDirectory::nameOf(self::PROPERTY) . '/finding/input', [1, 7, 1, 3, 1, 9, 0]);

        [$exit, $output] = $this->regress(['--phpunit', '--configuration', self::PHPUNIT . '/phpunit.xml']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('LogicException at ' . self::PHPUNIT . '/src/Classifier.php:38', $output);
        $this->assertStringEndsWith("\n\nReplayed 1 input of 2 properties, 1 failed.\n", $output);
    }

    public function testReportsAPhpunitTestSuiteWithoutPropertiesThatMatchTheFilter(): void
    {
        [$exit, $output] = $this->regress(['--phpunit', '--configuration', self::PHPUNIT . '/phpunit.xml', '--filter', 'DoesNotMatchAnyProperty']);

        $this->assertSame(2, $exit);
        $this->assertSame("The PHPUnit test suite has no properties that match the filter\n", $output);
    }

    public function testReportsThatThePropertiesOfAPhpunitTestSuiteCannotBeListed(): void
    {
        [$exit, $output] = $this->regress(['--phpunit', '--configuration', 'tests/fixtures/source-resolver/with-invalid-phpunit-xml/phpunit.xml']);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('The properties cannot be listed: PHPUnit cannot be booted: ', $output);
    }

    public function testReportsThatThePropertiesOfAPhpunitTestSuiteCannotBeListedWhenTheWorkerEndsBeforeItListedThem(): void
    {
        [$exit, $output] = $this->regress(['--phpunit', '--configuration', self::PHPUNIT . '/exit.xml']);

        $this->assertSame(2, $exit);
        $this->assertSame("The worker process ended before it listed the properties\n\nThe test suite cannot be booted\n", $output);
    }

    public function testReportsThatThePropertiesOfAPhpunitTestSuiteCannotBeListedWhenTheWorkerWritesWhatIsNotAFrame(): void
    {
        [$exit, $output] = $this->regress(['--phpunit', '--configuration', self::PHPUNIT . '/stdout.xml']);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('The properties cannot be listed: Invalid frame: ', $output);
    }

    /**
     * @return list<string>
     */
    private function fixture(string $fixture): array
    {
        return [self::TARGETS . '/' . $fixture . '/target.php', '--bootstrap', self::TARGETS . '/bootstrap.php', '--source', self::TARGETS . '/' . $fixture . '/src'];
    }

    /**
     * @param list<non-negative-int> $choices
     */
    private function store(string $path, array $choices): void
    {
        $this->write($path, (new Encoder)->encode(new ChoiceSequence($choices)));
    }

    private function write(string $path, string $content): void
    {
        $file = $this->directory . '/' . $path;

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, $content);
    }

    /**
     * @param list<string> $arguments the arguments of "rattle regress"
     *
     * @return array{0: int, 1: string}
     */
    private function regress(array $arguments): array
    {
        ob_start();

        $exit = (new RegressCommand)->run(
            (new ArgumentsBuilder)->build(
                ['rattle', 'regress', ...$arguments, '--cache-directory', $this->directory, '--findings-directory', $this->directory . '/findings'],
            ),
        );

        return [$exit, (string) ob_get_clean()];
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

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
