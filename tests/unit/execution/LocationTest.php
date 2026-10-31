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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

#[CoversClass(Location::class)]
#[UsesClass(Input::class)]
#[UsesClass(InvalidArgumentException::class)]
#[Small]
final class LocationTest extends TestCase
{
    private const string FILE = 'tests/unit/execution/LocationTest.php';

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
}
