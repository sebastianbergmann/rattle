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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetLoader::class)]
#[CoversClass(InvalidTargetException::class)]
#[UsesClass(Target::class)]
#[Small]
final class TargetLoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/worker';

    public function testLoadsTheTargetThatATargetFileReturns(): void
    {
        $target = (new TargetLoader)->load(self::FIXTURES . '/bytes-target.php');

        $this->assertSame('bytes', $target->name());
        $this->assertSame(16, $target->maximumLength());
    }

    public function testRejectsATargetFileThatDoesNotExist(): void
    {
        $this->expectException(InvalidTargetException::class);
        $this->expectExceptionMessageIsOrContains('The target file "/does/not/exist.php" does not exist');

        (new TargetLoader)->load('/does/not/exist.php');
    }

    public function testRejectsATargetFileThatDoesNotReturnATarget(): void
    {
        $this->expectException(InvalidTargetException::class);
        $this->expectExceptionMessageIsOrContains('The target file "' . self::FIXTURES . '/not-a-target.php" must return an instance of ' . Target::class . ', string returned');

        (new TargetLoader)->load(self::FIXTURES . '/not-a-target.php');
    }
}
