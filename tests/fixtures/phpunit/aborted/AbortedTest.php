<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Phpunit;

use PHPUnit\Framework\Attributes\Property;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AbortedTest extends TestCase
{
    protected function setUp(): void
    {
        throw new RuntimeException('cannot be set up');
    }

    /**
     * @param list<int<0, 9>> $values
     */
    #[Property]
    public function testIsNeverChecked(array $values): void
    {
        $this->assertIsArray($values);
    }
}
