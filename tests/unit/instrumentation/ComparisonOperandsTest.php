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

#[CoversClass(ComparisonOperands::class)]
#[Small]
final class ComparisonOperandsTest extends TestCase
{
    public function testReportsEachOperandOnce(): void
    {
        $operands = new ComparisonOperands;

        $operands->add(['a', '10', 'b']);
        $operands->add(['b', 'c', '10']);

        $this->assertSame(['a', '10', 'b', 'c'], $operands->takeUnreported());
        $this->assertSame([], $operands->takeUnreported());

        $operands->add(['a', 'd']);

        $this->assertSame(['d'], $operands->takeUnreported());
    }
}
