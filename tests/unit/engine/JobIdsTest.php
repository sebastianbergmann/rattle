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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobIds::class)]
#[Small]
final class JobIdsTest extends TestCase
{
    public function testHandsOutConsecutiveIdsThatStartAtZero(): void
    {
        $ids = new JobIds;

        $this->assertSame(0, $ids->next());
        $this->assertSame(1, $ids->next());
        $this->assertSame(2, $ids->next());
    }
}
