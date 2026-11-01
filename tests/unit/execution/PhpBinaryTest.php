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

use const PHP_BINARY;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_VERSION;
use function sprintf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpBinary::class)]
#[Medium]
final class PhpBinaryTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/php';

    public function testTheBinaryThatRunsRattle(): void
    {
        $php = PhpBinary::current();

        $this->assertSame(PHP_BINARY, $php->path());
        $this->assertSame(PHP_VERSION, $php->version());
        $this->assertSame(sprintf('PHP %d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION), $php->label());
    }

    public function testRunsABinaryToDetermineItsVersion(): void
    {
        $php = PhpBinary::at(PHP_BINARY);

        $this->assertSame(PHP_BINARY, $php->path());
        $this->assertSame(PHP_VERSION, $php->version());
        $this->assertSame(PhpBinary::current()->label(), $php->label());
    }

    public function testABinaryMustBePhp85OrLater(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageIsOrContains(sprintf('The PHP binary "%s/php-8.4" is PHP 8.4.10, but rattle needs PHP 8.5 or later', self::FIXTURES));

        PhpBinary::at(self::FIXTURES . '/php-8.4');
    }

    public function testABinaryMustBePhp(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('"/bin/echo" is not a PHP binary that can be run');

        PhpBinary::at('/bin/echo');
    }

    public function testABinaryThatDoesNotExistCannotBeRun(): void
    {
        $this->expectException(ConfigurationException::class);

        PhpBinary::at('/does/not/exist/php');
    }
}
