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

#[CoversClass(SourceEdits::class)]
#[Small]
final class SourceEditsTest extends TestCase
{
    public function testAppliesEditsAtOffsetsIntoTheOriginalSource(): void
    {
        $edits = new SourceEdits('abcdef');

        $edits->insert(3, '-', 0);
        $edits->insert(0, '<', 0);
        $edits->replace(4, 1, 'E');
        $edits->insert(6, '>', 0);

        $this->assertSame('abcdef', $edits->source());
        $this->assertSame('<abc-dEf>', $edits->apply());
    }

    public function testOrdersInsertionsAtTheSameOffsetByPriorityAndThenInTheOrderInWhichTheyWereMade(): void
    {
        $edits = new SourceEdits('ab');

        $edits->insert(1, '3', 1);
        $edits->insert(1, '1', -1);
        $edits->insert(1, '4', 1);
        $edits->insert(1, '2', 0);

        $this->assertSame('a1234b', $edits->apply());
    }

    public function testInsertsBeforeAReplacementAtTheSameOffset(): void
    {
        $edits = new SourceEdits('a;b');

        $edits->replace(1, 1, '{}');
        $edits->insert(1, ')', 0);

        $this->assertSame('a){}b', $edits->apply());
    }

    public function testIgnoresInsertionsWithinTextThatWasReplaced(): void
    {
        $edits = new SourceEdits('abcd');

        $edits->replace(1, 2, 'X');
        $edits->insert(2, '-', 0);

        $this->assertSame('aXd', $edits->apply());
    }

    public function testLeavesTheSourceAsItIsWithoutEdits(): void
    {
        $this->assertSame('abc', (new SourceEdits('abc'))->apply());
    }
}
