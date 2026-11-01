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

use function dirname;
use function sprintf;
use function strlen;
use function unserialize;
use __PHP_Incomplete_Class;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\FileStreamWrapper;

#[CoversClass(Sentinel::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(Failure::class)]
#[UsesClass(FileStreamWrapper::class)]
#[UsesClass(Location::class)]
#[UsesClass(Vulnerability::class)]
#[Small]
final class SentinelTest extends TestCase
{
    private const string FILE = 'tests/unit/detectors/SentinelTest.php';

    protected function setUp(): void
    {
        Detectors::install(new Location(dirname(__DIR__, 3)), static fn (string $file): bool => false);
    }

    protected function tearDown(): void
    {
        Detectors::uninstall();
    }

    public function testUnserializingAnObjectOfTheSentinelClassIsAnUnsafeDeserialization(): void
    {
        Detectors::start();

        try {
            $line = __LINE__ + 1;
            unserialize(self::serialized());
        } catch (UnsafeDeserialization $vulnerability) {
            // the vulnerability is thrown into the code that unserializes
        } finally {
            $failure = Detectors::stop();
        }

        $this->assertInstanceOf(UnsafeDeserialization::class, $vulnerability ?? null);
        $this->assertSame(sprintf('%s at %s:%d', UnsafeDeserialization::class, self::FILE, $line), $failure?->describe());
    }

    public function testUnserializingTheSentinelClassIsNotDetectedWhenNoClassesAreAllowed(): void
    {
        Detectors::start();

        $object = unserialize(self::serialized(), ['allowed_classes' => false]);

        $this->assertNull(Detectors::stop());
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $object);
    }

    public function testCreatingAnObjectOfTheSentinelClassInCodeThatIsEvaluatedIsACodeInjection(): void
    {
        Detectors::start();

        try {
            $line = __LINE__ + 1;
            eval('new \SebastianBergmann\Rattle\Sentinel;');
        } catch (CodeInjection $vulnerability) {
            // the vulnerability is thrown into the code that is evaluated
        } finally {
            $failure = Detectors::stop();
        }

        $this->assertInstanceOf(CodeInjection::class, $vulnerability ?? null);
        $this->assertSame(sprintf('%s at %s:%d', CodeInjection::class, self::FILE, $line), $failure?->describe());
    }

    public function testCreatingAnObjectOfTheSentinelClassIsDetectedWhileNoInputIsExecuted(): void
    {
        $this->expectException(CodeInjection::class);

        new Sentinel;
    }

    private static function serialized(): string
    {
        return sprintf('O:%d:"%s":0:{}', strlen(Sentinel::class), Sentinel::class);
    }
}
