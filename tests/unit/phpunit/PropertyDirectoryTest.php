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

#[CoversClass(PropertyDirectory::class)]
#[Small]
final class PropertyDirectoryTest extends TestCase
{
    public function testIsNamedAfterTheTestClassAndTheTestMethodOfTheProperty(): void
    {
        $this->assertMatchesRegularExpression('/^parsertest-testroundtrip-[0-9a-f]{8}$/', PropertyDirectory::nameOf('Vendor\Package\ParserTest::testRoundTrip'));
    }

    public function testTellsPropertiesWithTheSameNameInDifferentNamespacesApart(): void
    {
        $this->assertNotSame(PropertyDirectory::nameOf('First\ParserTest::testRoundTrip'), PropertyDirectory::nameOf('Second\ParserTest::testRoundTrip'));
    }

    public function testHasOnlyTheCharactersOfTheNamesOfFuzzTargets(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', PropertyDirectory::nameOf('ParserTest::testRoundTrip#data set "a b" (repetition 1 of 2)'));
    }
}
