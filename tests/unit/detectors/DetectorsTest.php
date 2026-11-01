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
use function file_exists;
use function file_get_contents;
use function fopen;
use function mkdir;
use function opendir;
use function realpath;
use function rename;
use function sprintf;
use function str_repeat;
use function touch;
use function unlink;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SebastianBergmann\Rattle\Fixture\Detectors\Library;
use SebastianBergmann\Rattle\Instrumentation\FileStreamWrapper;

#[CoversClass(Detectors::class)]
#[CoversClass(Vulnerability::class)]
#[UsesClass(Failure::class)]
#[UsesClass(FileStreamWrapper::class)]
#[UsesClass(Location::class)]
#[UsesClass(Sentinels::class)]
#[Small]
final class DetectorsTest extends TestCase
{
    private const string FILE     = 'tests/unit/detectors/DetectorsTest.php';
    private const string SENTINEL = '/rattle-sentinel';

    /**
     * @return array<string, array{0: class-string<Vulnerability>, 1: Closure(): mixed}>
     */
    public static function operations(): array
    {
        $climbing = 'data/' . str_repeat('../', 16) . 'rattle-sentinel';

        // the file is never included, as the detector prevents it
        /** @phpstan-ignore include.fileNotFound */
        $including = static fn (): mixed => include $climbing . '.php';

        return [
            'reading a file'             => [PathTraversal::class, static fn (): mixed => file_get_contents($climbing)],
            'writing a file'             => [PathTraversal::class, static fn (): mixed => fopen(self::SENTINEL, 'w')],
            'checking whether it exists' => [PathTraversal::class, static fn (): mixed => file_exists(self::SENTINEL)],
            'touching a file'            => [PathTraversal::class, static fn (): mixed => touch(self::SENTINEL)],
            'deleting a file'            => [PathTraversal::class, static fn (): mixed => unlink(self::SENTINEL)],
            'renaming a file to it'      => [PathTraversal::class, static fn (): mixed => rename(__FILE__, self::SENTINEL)],
            'creating a directory'       => [PathTraversal::class, static fn (): mixed => mkdir(self::SENTINEL)],
            'opening a directory'        => [PathTraversal::class, static fn (): mixed => opendir(self::SENTINEL)],
            'including a file'           => [CodeInjection::class, $including],
        ];
    }

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/fixtures/detectors/Library.php';
    }

    protected function tearDown(): void
    {
        Detectors::uninstall();
        FileStreamWrapper::unregister();
    }

    public function testReportsTheFirstDetectionDuringTheExecutionOfAnInput(): void
    {
        $this->install(static fn (string $file): bool => false);

        Detectors::start();

        $line  = __LINE__ + 1;
        $first = Detectors::detect(PathTraversal::class, 'first');

        Detectors::detect(CodeInjection::class, 'second');

        $failure = Detectors::stop();

        $this->assertSame(__FILE__, $first->getFile());
        $this->assertSame($line, $first->getLine());
        $this->assertSame('first', $first->getMessage());
        $this->assertSame(sprintf('%s at %s:%d', PathTraversal::class, self::FILE, $line), $failure?->describe());
        $this->assertSame('first', $failure->message());
        $this->assertStringStartsWith(self::FILE . ':' . $line, $failure->trace());

        Detectors::start();

        $this->assertNull(Detectors::detection());
        $this->assertNull(Detectors::stop());
    }

    public function testRecordsNoDetectionWhileNoInputIsExecuted(): void
    {
        $vulnerability = Detectors::detect(PathTraversal::class, 'outside of an execution');

        $this->assertSame(__FILE__, $vulnerability->getFile());
        $this->assertNull(Detectors::detection());
    }

    public function testReportsADetectionAtTheInnermostFrameInTheSourceDirectories(): void
    {
        $this->install(static fn (string $file): bool => $file === __FILE__);

        $line          = __LINE__ + 1;
        $vulnerability = Library::sink();

        $this->assertSame(__FILE__, $vulnerability->getFile());
        $this->assertSame($line, $vulnerability->getLine());
    }

    public function testReportsADetectionAtTheInnermostFrameThatIsNotRattlesOwnCodeWhenNoFrameLiesInTheSourceDirectories(): void
    {
        $this->install(static fn (string $file): bool => false);

        $vulnerability = Library::sink();
        $start         = (new ReflectionMethod(Library::class, 'sink'))->getStartLine();

        $this->assertIsInt($start);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/fixtures/detectors/Library.php'), $vulnerability->getFile());
        $this->assertSame($start + 2, $vulnerability->getLine());
    }

    /**
     * @param class-string<Vulnerability> $expected
     * @param Closure(): mixed            $operation
     */
    #[DataProvider('operations')]
    public function testDetectsAnOperationOnAPathThatLeadsToTheSentinelOfTheFileSystem(string $expected, Closure $operation): void
    {
        $this->install(static fn (string $file): bool => false);

        Detectors::start();

        try {
            $operation();
        } catch (Vulnerability $vulnerability) {
            // the vulnerability is thrown into the code that operates on the path
        } finally {
            $failure = Detectors::stop();
        }

        $this->assertInstanceOf($expected, $vulnerability ?? null);
        $this->assertStringStartsWith($expected . ' at ', (string) $failure?->describe());
    }

    public function testDoesNotDetectAPathThatDoesNotLeadToTheSentinelOfTheFileSystem(): void
    {
        $this->install(static fn (string $file): bool => false);

        Detectors::start();

        $exists = file_exists(__DIR__ . '/rattle-sentinel');

        $this->assertNull(Detectors::stop());
        $this->assertFalse($exists);
    }

    public function testDoesNotDetectAnythingWhileNoInputIsExecuted(): void
    {
        $this->install(static fn (string $file): bool => false);

        $this->assertFalse(file_exists(self::SENTINEL));
    }

    /**
     * @param Closure(string): bool $isSource
     */
    private function install(Closure $isSource): void
    {
        FileStreamWrapper::register(static fn (string $path): ?string => null);

        Detectors::install(new Location(dirname(__DIR__, 3)), $isSource);
    }
}
