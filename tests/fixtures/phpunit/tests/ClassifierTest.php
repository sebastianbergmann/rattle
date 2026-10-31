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

use function count;
use PHPUnit\Framework\Attributes\Property;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    /**
     * @param list<int<0, 9>> $values
     */
    #[Property]
    public function testClassifiesEveryList(array $values): void
    {
        $this->assertIsString(new Classifier()->classify($values));
    }

    /**
     * @param list<int<0, 9>> $values
     */
    #[Property]
    public function testCountsAreNotNegative(array $values): void
    {
        $this->assertGreaterThanOrEqual(0, count($values));
    }
}
