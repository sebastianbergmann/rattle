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
            Edges::$hits,
        );
        $this->assertSame(2, Edges::$previous);
    }

    public function testCountsTheEdgesBetweenTheBlocksThatAreEnteredWithStatements(): void
    {
        Edges::reset();

        eval(Edges::statementFor(3) . ' ' . Edges::statementFor(4) . ' ' . Edges::statementFor(3) . ' ' . Edges::statementFor(4));

        $this->assertSame([3 => 1, 3 << 28 | 4 => 2, 4 << 28 | 3 => 1], Edges::$hits);
        $this->assertSame(4, Edges::$previous);
    }

    public function testCanBeReset(): void
    {
        Edges::through(1, null);
        Edges::reset();

        $this->assertSame([], Edges::$hits);
        $this->assertSame(0, Edges::$previous);
    }
}
