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
use function explode;
use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

#[CoversClass(Location::class)]
#[UsesClass(Input::class)]
#[UsesClass(InvalidArgumentException::class)]
#[Small]
final class LocationTest extends TestCase
{
    private const string FILE             = 'tests/unit/execution/LocationTest.php';
    private const string EXPANDER         = 'tests/fixtures/targets/recursion/src/TemplateExpander.php';
    private const string CALL_STACK_LIMIT = 'Maximum call stack size of 8339456 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?';

    public function testTheLocationOfAThrowableIsItsOwnFileAndLine(): void
    {
        $line      = __LINE__ + 1;
        $throwable = new RuntimeException;

        $this->assertSame(self::FILE . ':' . $line, $this->location()->of($throwable));
        $this->assertStringStartsWith(self::FILE . ':' . $line . "\n", $this->location()->traceOf($throwable));
    }

    public function testTheLocationOfAThrowableThatIsRaisedInRattleIsTheFirstFrameOutsideOfIt(): void
    {
        $line = __LINE__ + 3;

        try {
            new Input(ChoiceSource::replay(new ChoiceSequence([])), false)->pick([]);
        } catch (InvalidArgumentException $e) {
            $this->assertSame(self::FILE . ':' . $line, $this->location()->of($e));

            $trace = explode("\n", $this->location()->traceOf($e));

            $this->assertStringStartsWith(self::FILE . ':' . $line . ' ' . Input::class . '->pick()', $trace[0] ?? '');
        }
    }

    public function testTheLocationOfTheErrorForTheCallStackLimitIsTheFirstOfTheFramesThatAreRepeated(): void
    {
        // the limit is reached by the call in line 54 or by the call in line
        // 56, depending on how deep the call stack was when the recursion
        // started
        $this->assertSame(self::EXPANDER . ':54', $this->location()->of($this->recursion(self::CALL_STACK_LIMIT, 54)));
        $this->assertSame(self::EXPANDER . ':54', $this->location()->of($this->recursion(self::CALL_STACK_LIMIT, 56)));
        $this->assertSame(self::EXPANDER . ':56', $this->location()->of($this->error(self::CALL_STACK_LIMIT, 56, [])));
    }

    public function testTheLocationOfOtherErrorsInARecursionIsTheFirstFrameOfTheirStackTrace(): void
    {
        // the stack trace contains the file and line of the error already
        $this->assertSame(self::EXPANDER . ':56', $this->location()->of($this->recursion('Something else', 54)));
        $this->assertSame(self::EXPANDER . ':54', $this->location()->of($this->recursion('Something else', 56)));
    }

    public function testFramesWhoseFileIsNotAFileAreLeftOut(): void
    {
        $line = __LINE__ + 3;

        try {
            eval('throw new \\RuntimeException;');
        } catch (RuntimeException $e) {
            $this->assertSame(self::FILE . ':' . $line, $this->location()->of($e));
        }
    }

    public function testFramesThatAreRepeatedAreRenderedOnce(): void
    {
        $this->assertSame(
            [
                self::EXPANDER . ':54',
                self::EXPANDER . ':56 expand()',
                self::EXPANDER . ':54 preg_replace_callback()',
                '(the 2 frames above are repeated 9 more times)',
                self::EXPANDER . ':46 expand()',
                'tests/fixtures/targets/recursion/target.php:18 expandAll()',
            ],
            explode("\n", $this->location()->traceOf($this->recursion(self::CALL_STACK_LIMIT, 54))),
        );
    }

    public function testAFrameIsRenderedOnceWhenItIsRepeatedAtLeastThreeTimes(): void
    {
        $frame = ['file' => dirname(__DIR__, 3) . '/' . self::FILE, 'line' => 1, 'function' => 'f'];

        $this->assertSame(self::FILE . ":1 f()\n" . self::FILE . ':1 f()', $this->location()->render([$frame, $frame]));
        $this->assertSame(self::FILE . ":1 f()\n(the frame above is repeated 2 more times)", $this->location()->render([$frame, $frame, $frame]));
    }

    public function testThePathOfAFileBelowTheWorkingDirectoryIsRelativeToIt(): void
    {
        $this->assertSame(self::FILE . ':12', $this->location()->ofIssue(__FILE__, 12));
        $this->assertSame(__FILE__, (new Location('/does/not/exist'))->file(__FILE__));
        $this->assertSame('/elsewhere/file.php', $this->location()->file('/elsewhere/file.php'));
        $this->assertSame(self::FILE, (new Location(dirname(__DIR__, 3) . '/'))->file(__FILE__));
    }

    public function testKnowsRattlesOwnCode(): void
    {
        $this->assertTrue(Location::isRattle(dirname(__DIR__, 3) . '/src/target/Input.php'));
        $this->assertTrue(Location::isRattle(dirname(__DIR__, 3) . '/rattle'));
        $this->assertFalse(Location::isRattle(__FILE__));
    }

    private function location(): Location
    {
        return new Location(dirname(__DIR__, 3));
    }

    /**
     * Returns an error that is raised by the call in the given line of a
     * recursion such as the one of the recursion fixture, in which the calls
     * in lines 54 and 56 call each other ten times. As in the stack trace of a
     * real recursion, the first frame is the call in the other line, which led
     * to the given one.
     */
    private function recursion(string $message, int $line): Error
    {
        $expander = dirname(__DIR__, 3) . '/' . self::EXPANDER;
        $other    = $line === 54 ? 56 : 54;
        $function = static fn (int $line): string => $line === 54 ? 'preg_replace_callback' : 'expand';
        $trace    = [];

        for ($i = 0; $i < 10; $i++) {
            $trace[] = ['file' => $expander, 'line' => $other, 'function' => $function($other)];
            $trace[] = ['file' => $expander, 'line' => $line, 'function' => $function($line)];
        }

        $trace[] = ['file' => $expander, 'line' => 46, 'function' => 'expand'];
        $trace[] = ['file' => dirname(__DIR__, 3) . '/tests/fixtures/targets/recursion/target.php', 'line' => 18, 'function' => 'expandAll'];

        return $this->error($message, $line, $trace);
    }

    /**
     * Returns an error that is raised in the given line of the recursion
     * fixture, with the given stack trace.
     *
     * @param list<array{file: string, line: int, function: string}> $trace
     */
    private function error(string $message, int $line, array $trace): Error
    {
        $error = new Error($message);

        (new ReflectionProperty(Error::class, 'file'))->setValue($error, dirname(__DIR__, 3) . '/' . self::EXPANDER);
        (new ReflectionProperty(Error::class, 'line'))->setValue($error, $line);
        (new ReflectionProperty(Error::class, 'trace'))->setValue($error, $trace);

        return $error;
    }
}
