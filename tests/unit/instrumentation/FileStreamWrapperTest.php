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

use const LOCK_EX;
use const LOCK_UN;
use const SEEK_SET;
use function chmod;
use function clearstatcache;
use function closedir;
use function fclose;
use function feof;
use function fflush;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fileperms;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function ftruncate;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function opendir;
use function readdir;
use function realpath;
use function rename;
use function rewinddir;
use function rmdir;
use function scandir;
use function sort;
use function stream_set_blocking;
use function stream_set_write_buffer;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileStreamWrapper::class)]
#[Small]
final class FileStreamWrapperTest extends TestCase
{
    private string $directory;

    /**
     * @var list<string>
     */
    private array $transformed = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-stream-wrapper-' . uniqid();

        mkdir($this->directory);

        $this->directory = (string) realpath($this->directory);

        file_put_contents($this->directory . '/transformed.php', "<?php return 'original';\n");
        file_put_contents($this->directory . '/untouched.php', "<?php return 'untouched';\n");

        FileStreamWrapper::register(
            function (string $path): ?string
            {
                $this->transformed[] = $path;

                if ($path === $this->directory . '/transformed.php') {
                    return "<?php return 'transformed from ' . __FILE__;\n";
                }

                return null;
            },
        );
    }

    protected function tearDown(): void
    {
        FileStreamWrapper::unregister();

        foreach ((array) scandir($this->directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->directory . '/' . $entry;

            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }

        rmdir($this->directory);
    }

    public function testIncludesTheTransformedCodeOfAFileUnderItsOwnPath(): void
    {
        $this->assertTrue(FileStreamWrapper::isRegistered());
        $this->assertSame('transformed from ' . $this->directory . '/transformed.php', include $this->directory . '/transformed.php');
        $this->assertSame([$this->directory . '/transformed.php'], $this->transformed);
    }

    public function testIncludesTheCodeOfAFileThatIsNotTransformed(): void
    {
        $this->assertSame('untouched', include $this->directory . '/untouched.php');
        $this->assertTrue(require_once $this->directory . '/untouched.php');
        $this->assertSame([$this->directory . '/untouched.php'], $this->transformed);
    }

    public function testDoesNotTransformFilesThatAreReadButNotIncluded(): void
    {
        $this->assertSame("<?php return 'original';\n", file_get_contents($this->directory . '/transformed.php'));
        $this->assertSame([], $this->transformed);
    }

    public function testReadsAndWritesFiles(): void
    {
        $path   = $this->directory . '/file.txt';
        $stream = fopen($path, 'w+');

        $this->assertIsResource($stream);
        $this->assertSame(6, fwrite($stream, 'abcdef'));
        $this->assertTrue(fflush($stream));
        $this->assertSame(6, ftell($stream));
        $this->assertSame(0, fseek($stream, 1, SEEK_SET));
        $this->assertSame('bc', fread($stream, 2));
        $this->assertTrue(ftruncate($stream, 4));
        $this->assertSame(4, fstat($stream)['size'] ?? null);
        $this->assertTrue(flock($stream, LOCK_EX));
        $this->assertTrue(flock($stream, LOCK_UN));
        $this->assertTrue(stream_set_blocking($stream, true));
        // plain files have no write buffer, as with PHP's own wrapper
        $this->assertSame(-1, stream_set_write_buffer($stream, 0));
        // PHP buffers what it reads through a userland wrapper, so the stream
        // is read again from the start after it was truncated
        $this->assertSame(0, fseek($stream, 0, SEEK_SET));
        $this->assertSame('abcd', fread($stream, 10));
        $this->assertSame('', fread($stream, 10));
        $this->assertTrue(feof($stream));
        $this->assertTrue(fclose($stream));

        $this->assertSame('abcd', file_get_contents($path));
        $this->assertSame(3, file_put_contents($path, 'xyz'));
        $this->assertSame('xyz', file_get_contents($path));
    }

    public function testReportsFilesThatCannotBeOpened(): void
    {
        $this->assertFalse(@fopen($this->directory . '/missing/file.txt', 'r'));
    }

    public function testInspectsAndChangesTheMetadataOfFiles(): void
    {
        $path = $this->directory . '/file.txt';

        $this->assertTrue(touch($path, 1000000000));
        $this->assertTrue(is_file($path));
        $this->assertFalse(is_dir($path));
        $this->assertFalse(file_exists($this->directory . '/missing.txt'));

        clearstatcache();

        $this->assertSame(1000000000, filemtime($path));
        $this->assertTrue(chmod($path, 0o600));

        clearstatcache();

        $this->assertSame(0o600, fileperms($path) & 0o777);
        $this->assertTrue(symlink($path, $this->directory . '/link'));
        $this->assertTrue(is_link($this->directory . '/link'));
    }

    public function testManagesFilesAndDirectories(): void
    {
        $this->assertTrue(mkdir($this->directory . '/a/b', 0o777, true));
        $this->assertTrue(rename($this->directory . '/a/b', $this->directory . '/c'));
        $this->assertTrue(rmdir($this->directory . '/a'));

        file_put_contents($this->directory . '/c/file.txt', 'x');

        $this->assertSame(['.', '..', 'file.txt'], $this->sorted(scandir($this->directory . '/c')));
        $this->assertTrue(unlink($this->directory . '/c/file.txt'));
        $this->assertTrue(rmdir($this->directory . '/c'));
        $this->assertFalse(@mkdir($this->directory . '/missing/directory'));
    }

    public function testReadsDirectories(): void
    {
        $directory = opendir($this->directory);

        $this->assertIsResource($directory);

        $entries = [];

        while (($entry = readdir($directory)) !== false) {
            $entries[] = $entry;
        }

        rewinddir($directory);

        $this->assertNotFalse(readdir($directory));

        closedir($directory);

        $this->assertSame(['.', '..', 'transformed.php', 'untouched.php'], $this->sorted($entries));
        $this->assertFalse(@opendir($this->directory . '/missing'));
    }

    public function testOnlyOneTransformationCanBeRegistered(): void
    {
        $this->expectException(LogicException::class);

        FileStreamWrapper::register(static fn (string $path): ?string => null);
    }

    public function testRestoresPhpsOwnWrapperWhenItIsUnregistered(): void
    {
        FileStreamWrapper::unregister();
        FileStreamWrapper::unregister();

        $this->assertFalse(FileStreamWrapper::isRegistered());
        $this->assertSame('original', include $this->directory . '/transformed.php');
        $this->assertSame([], $this->transformed);
    }

    /**
     * @param array<string>|false $entries
     *
     * @return list<string>
     */
    private function sorted(array|false $entries): array
    {
        $this->assertIsArray($entries);

        $sorted = [];

        foreach ($entries as $entry) {
            $sorted[] = $entry;
        }

        sort($sorted);

        return $sorted;
    }
}
