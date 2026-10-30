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
    public function testTheLocationOfAThrowableIsItsOwnFileAndLine(): void
    {
        $line      = __LINE__ + 1;
        $throwable = new RuntimeException;

        $this->assertSame(__FILE__ . ':' . $line, Location::of($throwable));
        $this->assertStringStartsWith(__FILE__ . ':' . $line . "\n", Location::traceOf($throwable));
    }

    public function testTheLocationOfAThrowableThatIsRaisedInRattleIsTheFirstFrameOutsideOfIt(): void
    {
        $line = __LINE__ + 3;

        try {
            new Input(ChoiceSource::replay(new ChoiceSequence([])), false)->pick([]);
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__FILE__ . ':' . $line, Location::of($e));

            $trace = explode("\n", Location::traceOf($e));

            $this->assertStringStartsWith(__FILE__ . ':' . $line . ' ' . Input::class . '->pick()', $trace[0] ?? '');
        }
    }

    public function testKnowsRattlesOwnCode(): void
    {
        $this->assertTrue(Location::isRattle(dirname(__DIR__, 3) . '/src/target/Input.php'));
        $this->assertTrue(Location::isRattle(dirname(__DIR__, 3) . '/rattle'));
        $this->assertFalse(Location::isRattle(__FILE__));
    }
}
