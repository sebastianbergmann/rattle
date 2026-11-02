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

use function count;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Edges::class)]
#[Small]
final class EdgesTest extends TestCase
{
    protected function tearDown(): void
    {
        Edges::reset();
    }

    public function testCountsTheEdgesBetweenTheBlocksThatAreEnteredWithinExpressions(): void
    {
        Edges::reset();

        $this->assertSame('value', Edges::through(1, 'value'));

        Edges::through(2, null);
        Edges::through(1, null);
        Edges::through(2, null);

        $this->assertSame(
            [
                0 << 28 | 1 => 1,
                1 << 28 | 2 => 2,
                2 << 28 | 1 => 1,
            ],
            Edges::hits(),
        );
        $this->assertSame(2 << 28, Edges::$previous);
    }

    public function testCountsTheEdgesBetweenTheBlocksThatAreEnteredWithStatements(): void
    {
        Edges::reset();

        eval(Edges::statementFor(3, false) . ' ' . Edges::statementFor(4, true) . ' ' . Edges::statementFor(3, false) . ' ' . Edges::statementFor(4, true));

        $this->assertSame([3 => 1, 3 << 28 | 4 => 2, 4 << 28 | 3 => 1], Edges::hits());
        $this->assertSame(4 << 28, Edges::$previous);
    }

    public function testCountsTheEdgesThatWereTakenWhileTheTraceWasCountedAndAfterwards(): void
    {
        Edges::reset();

        Edges::through(1, null);
        Edges::through(2, null);
        Edges::countTrace();
        Edges::through(1, null);
        Edges::through(2, null);
        Edges::through(3, null);

        $this->assertSame([1 => 1, 1 << 28 | 2 => 2, 2 << 28 | 1 => 1, 2 << 28 | 3 => 1], Edges::hits());
    }

    public function testTheStatementAtTheStartOfABlockThatIsEnteredAgainAndAgainCountsTheTraceWhenItHasBecomeLong(): void
    {
        Edges::reset();

        eval('for ($i = 0; $i < 100000; $i++) { ' . Edges::statementFor(5, true) . ' }');

        $this->assertLessThanOrEqual(65536, count(Edges::$trace));
        $this->assertSame([5 => 1, 5 << 28 | 5 => 99999], Edges::hits());
    }

    public function testThroughCountsTheTraceWhenItHasBecomeLong(): void
    {
        Edges::reset();

        for ($i = 0; $i < 100000; $i++) {
            Edges::through(5, null);
        }

        $this->assertLessThanOrEqual(65536, count(Edges::$trace));
        $this->assertSame([5 => 1, 5 << 28 | 5 => 99999], Edges::hits());
    }

    public function testCanBeReset(): void
    {
        Edges::through(1, null);
        Edges::reset();

        $this->assertSame([], Edges::hits());
        $this->assertSame([], Edges::$trace);
        $this->assertSame(0, Edges::$previous);
    }
}
