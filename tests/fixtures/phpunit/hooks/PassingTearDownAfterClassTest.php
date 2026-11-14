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

final class PassingTearDownAfterClassTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
    }

    /**
     * @param int<0, 9> $value
     */
    #[Property]
    public function testIsChecked(int $value): void
    {
        $this->assertLessThan(10, $value);
    }
}
