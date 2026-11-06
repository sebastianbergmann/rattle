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

use function realpath;
use function SebastianBergmann\Rattle\Fixture\IncludeHook\classify;
use function SebastianBergmann\Rattle\Fixture\IncludeHook\excluded;
use function SebastianBergmann\Rattle\Fixture\IncludeHook\vendored;
use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncludeHook::class)]
#[UsesClass(Instrumentor::class)]
#[UsesClass(BlockFinder::class)]
#[UsesClass(BlockStart::class)]
#[UsesClass(SourceEdits::class)]
#[UsesClass(Edges::class)]
#[UsesClass(FileStreamWrapper::class)]
#[UsesClass(InstrumentationFailedException::class)]
#[Small]
final class IncludeHookTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/instrumentation/include-hook';

    protected function tearDown(): void
    {
        Edges::reset();
    }

    public function testInstrumentsOnlyFilesBelowTheSourcesThatAreNotExcluded(): void
    {
        $source = (string) realpath(self::FIXTURES);
        $hook   = new IncludeHook(new Instrumentor, [$source], [$source . '/source/excluded.php']);

        $this->assertTrue($hook->shouldInstrument($source . '/source/functions.php'));
        $this->assertFalse($hook->shouldInstrument($source . '/source/excluded.php'));
        $this->assertFalse($hook->shouldInstrument($source . '/vendor/package/vendored.php'));
        $this->assertFalse($hook->shouldInstrument($source . 'other/file.php'));
        $this->assertFalse($hook->shouldInstrument('/elsewhere/file.php'));
        $this->assertFalse($hook->shouldInstrument((string) realpath(__DIR__ . '/../../../src/instrumentation/Edges.php')));
    }

    public function testInstrumentsASourceDirectoryInAVendorDirectoryButNotTheVendorDirectoriesBelowIt(): void
    {
        $source = (string) realpath(self::FIXTURES . '/vendor/package');
        $hook   = new IncludeHook(new Instrumentor, [$source], []);

        $this->assertTrue($hook->shouldInstrument($source . '/vendored.php'));
        $this->assertFalse($hook->shouldInstrument($source . '/vendor/other/file.php'));
    }

    public function testInstrumentsAVendorDirectoryBelowASourceDirectoryWhenItIsASourceDirectoryItself(): void
    {
        $root    = (string) realpath(self::FIXTURES);
        $package = (string) realpath(self::FIXTURES . '/vendor/package');
        $hook    = new IncludeHook(new Instrumentor, [$root, $package], []);

        $this->assertTrue($hook->shouldInstrument($package . '/vendored.php'));
        $this->assertFalse($hook->shouldInstrument($root . '/vendor/other/file.php'));
    }

    public function testInstrumentsFilesWhileTheyAreIncluded(): void
    {
        $hook = new IncludeHook(new Instrumentor, [self::FIXTURES], [self::FIXTURES . '/source/excluded.php']);

        $hook->register();

        try {
            require_once self::FIXTURES . '/source/functions.php';

            require_once self::FIXTURES . '/source/excluded.php';

            require_once self::FIXTURES . '/vendor/package/vendored.php';
        } finally {
            $hook->unregister();
        }

        // only the file below the sources that is not excluded and not in a
        // vendor directory below them was instrumented
        $this->assertSame(1, $hook->numberOfInstrumentedFiles());

        Edges::reset();
        excluded(1);
        vendored(1);
        $this->assertSame([], Edges::hits());

        Edges::reset();
        $this->assertSame('positive', classify(1));
        $positive = Edges::hits();

        Edges::reset();
        $this->assertSame('not positive', classify(0));
        $notPositive = Edges::hits();

        $this->assertCount(2, $positive);
        $this->assertCount(2, $notPositive);
        $this->assertNotSame($positive, $notPositive);
    }

    public function testIncludesAFileThatCannotBeInstrumentedWithoutInstrumentation(): void
    {
        $hook = new IncludeHook(new Instrumentor, [self::FIXTURES], []);

        $hook->register();

        try {
            require self::FIXTURES . '/source/broken.php';
        } catch (ParseError) {
        } finally {
            $hook->unregister();
        }

        $this->assertCount(1, $hook->failures());
        $this->assertSame(0, $hook->numberOfInstrumentedFiles());
        $this->assertStringStartsWith('Cannot instrument', $hook->failures()[(string) realpath(self::FIXTURES . '/source/broken.php')] ?? '');
    }
}
