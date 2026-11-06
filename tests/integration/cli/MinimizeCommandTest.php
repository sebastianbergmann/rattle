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

use const SIG_DFL;
use const SIGINT;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function ob_get_clean;
use function ob_start;
use function pcntl_signal;
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
 * Minimizes inputs of a fixture target with "rattle minimize", with workers.
 */
#[CoversClass(MinimizeCommand::class)]
#[UsesClass(Arguments::class)]
#[UsesClass(ArgumentsBuilder::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Frame::class)]
#[UsesClass(FrameBuffer::class)]
#[UsesClass(Job::class)]
#[UsesClass(Minimization::class)]
#[UsesClass(Minimizer::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(PhpBinary::class)]
#[UsesClass(ProcessExecutor::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(RunCommand::class)]
#[UsesClass(Setup::class)]
#[UsesClass(SourceResolver::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(WorkerConfiguration::class)]
#[UsesClass(WorkerProcess::class)]
#[Large]
final class MinimizeCommandTest extends TestCase
{
    private const string FIXTURES = 'tests/fixtures/targets';
    private string $input;

    protected function setUp(): void
    {
        $this->input = sys_get_temp_dir() . '/rattle-minimize-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->input, $this->input . '.minimized'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        pcntl_signal(SIGINT, SIG_DFL);
    }

    public function testWritesTheMinimalInputThatMakesTheTargetFailInTheSameWayNextToTheInput(): void
    {
        $this->write("RATL\x00 with more bytes");

        [$exit, $output] = $this->minimize();

        $this->assertSame(0, $exit);
        $this->assertSame(
            'Failure: DivisionByZeroError at ' . self::FIXTURES . '/magic/src/ImageDecoder.php:37' . "\n" .
            'Minimized from 43 to 11 choices.' . "\n" .
            'Wrote ' . $this->input . '.minimized' . "\n",
            $output,
        );
        $this->assertSame($this->encoded("RATL\x00"), file_get_contents($this->input . '.minimized'));
    }

    public function testKeepsAnInputThatDidNotFailInTheSameWayAgainWhenItWasReplayed(): void
    {
        // the target fails for the input once
        $this->write("\x01");

        [$exit, $output] = $this->minimize('flaky');

        $this->assertSame(0, $exit);
        $this->assertSame(
            'Failure: LogicException at ' . self::FIXTURES . '/flaky/target.php:22' . "\n" .
            'The input did not fail in the same way again when it was replayed, and was not minimized.' . "\n" .
            'Wrote ' . $this->input . '.minimized' . "\n",
            $output,
        );
        $this->assertSame($this->encoded("\x01"), file_get_contents($this->input . '.minimized'));
    }

    public function testDoesNotMinimizeAnInputThatDoesNotMakeTheTargetFail(): void
    {
        $this->write("RATL\x01");

        [$exit, $output] = $this->minimize();

        $this->assertSame(1, $exit);
        $this->assertSame("The input does not make the target fail.\n", $output);
        $this->assertFileDoesNotExist($this->input . '.minimized');
    }

    public function testRejectsAnInputFileThatDoesNotExist(): void
    {
        [$exit, $output] = $this->minimize();

        $this->assertSame(2, $exit);
        $this->assertSame('The input file "' . $this->input . '" does not exist' . "\n", $output);
    }

    private function write(string $bytes): void
    {
        file_put_contents($this->input, $this->encoded($bytes));
    }

    private function encoded(string $bytes): string
    {
        return (new Encoder)->encode(new ChoiceSequence(BytesEncoding::choicesFor($bytes, 64)));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function minimize(string $fixture = 'magic'): array
    {
        ob_start();

        $exit = (new MinimizeCommand)->run(
            (new ArgumentsBuilder)->build(
                [
                    'rattle',
                    'minimize',
                    self::FIXTURES . '/' . $fixture . '/target.php',
                    $this->input,
                    '--bootstrap',
                    self::FIXTURES . '/bootstrap.php',
                    '--source',
                    self::FIXTURES . '/' . $fixture,
                ],
            ),
        );

        return [$exit, (string) ob_get_clean()];
    }
}
