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
use function file_put_contents;
use function is_file;
use function ob_get_clean;
use function ob_start;
use function preg_quote;
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
 * Runs inputs of a fixture target with "rattle run", with workers.
 */
#[CoversClass(RunCommand::class)]
#[UsesClass(Arguments::class)]
#[UsesClass(ArgumentsBuilder::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(DifferentialExecutor::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Frame::class)]
#[UsesClass(FrameBuffer::class)]
#[UsesClass(Job::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(PhpBinary::class)]
#[UsesClass(ProcessExecutor::class)]
#[UsesClass(ProcessorCount::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(Setup::class)]
#[UsesClass(SourceResolver::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(WorkerConfiguration::class)]
#[UsesClass(WorkerProcess::class)]
#[Large]
final class RunCommandIntegrationTest extends TestCase
{
    private const string FIXTURES = 'tests/fixtures/targets';
    private string $input;

    protected function setUp(): void
    {
        $this->input = sys_get_temp_dir() . '/rattle-run-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_file($this->input)) {
            unlink($this->input);
        }
    }

    public function testShowsTheFailureOfAnInputThatMakesTheTargetFail(): void
    {
        file_put_contents($this->input, "RATL\x00");

        [$exit, $output] = $this->runCommand(['--raw']);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith("Outcome: failed\n\nFailure: DivisionByZeroError at " . self::FIXTURES . '/magic/src/ImageDecoder.php:37' . "\n\nMessage: Division by zero\n\nStack trace:\n  " . self::FIXTURES . '/magic/src/ImageDecoder.php:37', $output);
    }

    public function testShowsTheOutcomeOfAnInputInTheFormatOfChoiceSequences(): void
    {
        file_put_contents($this->input, (new Encoder)->encode(new ChoiceSequence(BytesEncoding::choicesFor("RATL\x01", 64))));

        [$exit, $output] = $this->runCommand([]);

        $this->assertSame(0, $exit);
        $this->assertSame("Outcome: passed\n\nDrawn values:\n  #1 \"RATL\\x01\"\n", $output);
    }

    public function testComparesTheOutcomesOfAnInputOnTheVersionsOfPhpOfThePhpBinaries(): void
    {
        file_put_contents($this->input, 'GIF8');

        [$exit, $output] = $this->runCommand(['--raw', '--php', PHP_BINARY]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Outcome: expected\n", $output);
        $this->assertStringEndsWith("The outcome is the same on all versions of PHP.\n", $output);
    }

    public function testComparesTheOutcomesOfAnInputThatMakesTheTargetFailOnTheVersionsOfPhpOfThePhpBinaries(): void
    {
        file_put_contents($this->input, "RATL\x00");

        [$exit, $output] = $this->runCommand(['--raw', '--php', PHP_BINARY]);

        $this->assertSame(1, $exit);
        $this->assertStringEndsWith("The outcome is the same on all versions of PHP.\n", $output);
    }

    public function testShowsTheDifferenceBetweenTheOutcomesOfAnInputOnTheVersionsOfPhpOfThePhpBinaries(): void
    {
        file_put_contents($this->input, "\x00");

        [$exit, $output] = $this->runCommand(['--raw', '--php', __DIR__ . '/../../fixtures/php/php-precision-5'], 'versions');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(" (php-precision-5)\nOutcome: failed\n", $output);
        $this->assertMatchesRegularExpression('/^Difference between PHP versions: .+: passes; .+ \\(php-precision-5\\): LengthException at ' . preg_quote(self::FIXTURES, '/') . '\\/versions\\/src\\/Ratio.php:32$/m', $output);
    }

    public function testRejectsAnInputFileThatDoesNotExist(): void
    {
        [$exit, $output] = $this->runCommand([]);

        $this->assertSame(2, $exit);
        $this->assertSame('The input file "' . $this->input . '" does not exist' . "\n", $output);
    }

    public function testRejectsAnInputFileThatIsNotAValidChoiceSequence(): void
    {
        file_put_contents($this->input, 'not a choice sequence');

        [$exit, $output] = $this->runCommand([]);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('The input file "' . $this->input . '" is not a valid choice sequence: ', $output);
    }

    public function testRejectsARawInputForATargetThatIsNotABytesTarget(): void
    {
        file_put_contents($this->input, 'input');

        [$exit, $output] = $this->rattle(['tests/fixtures/worker/target.php', $this->input, '--bootstrap', 'tests/fixtures/worker/bootstrap.php', '--raw']);

        $this->assertSame(2, $exit);
        $this->assertSame("A raw input can only be run for a bytes target\n", $output);
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options, string $fixture = 'magic'): array
    {
        return $this->rattle(
            [
                self::FIXTURES . '/' . $fixture . '/target.php',
                $this->input,
                '--bootstrap',
                self::FIXTURES . '/bootstrap.php',
                '--source',
                self::FIXTURES . '/' . $fixture . '/src',
                ...$options,
            ],
        );
    }

    /**
     * @param list<string> $arguments the arguments of "rattle run"
     *
     * @return array{0: int, 1: string}
     */
    private function rattle(array $arguments): array
    {
        ob_start();

        $exit = (new RunCommand)->run((new ArgumentsBuilder)->build(['rattle', 'run', ...$arguments]));

        return [$exit, (string) ob_get_clean()];
    }
}
