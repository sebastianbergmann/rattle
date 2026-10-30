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

use const STREAM_META_ACCESS;
use const STREAM_META_GROUP;
use const STREAM_META_GROUP_NAME;
use const STREAM_META_OWNER;
use const STREAM_META_OWNER_NAME;
use const STREAM_META_TOUCH;
use const STREAM_MKDIR_RECURSIVE;
use const STREAM_OPTION_BLOCKING;
use const STREAM_OPTION_READ_TIMEOUT;
use const STREAM_OPTION_WRITE_BUFFER;
use const STREAM_REPORT_ERRORS;
use const STREAM_URL_STAT_LINK;
use const STREAM_URL_STAT_QUIET;
use const STREAM_USE_PATH;
use function chgrp;
use function chmod;
use function chown;
use function closedir;
use function fclose;
use function feof;
use function fflush;
use function file_exists;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function lstat;
use function mkdir;
use function opendir;
use function readdir;
use function realpath;
use function rename;
use function rewind;
use function rewinddir;
use function rmdir;
use function stat;
use function stream_resolve_include_path;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_set_write_buffer;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function touch;
use function unlink;
use Closure;
use LogicException;

/**
 * Replaces PHP's stream wrapper for file:// while files are instrumented, so
 * that the code of a file can be changed when the file is included, before
 * PHP compiles it, while PHP still knows the file by its own path: __FILE__,
 * the files in stack traces, and include_once see the original path.
 *
 * When a file is opened for inclusion, the transformation is passed the real
 * path of the file and returns the code to compile instead, or null for the
 * code of the file. The transformation, and every other operation on files
 * and directories, is performed with PHP's own wrapper, which is restored for
 * the duration of the operation.
 *
 * Only one transformation can be registered at a time.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class FileStreamWrapper
{
    /**
     * The flag that PHP passes to stream_open() when it opens a file for
     * include or require (STREAM_OPEN_FOR_INCLUDE in its C API), for which it
     * does not define a constant.
     */
    private const int OPENED_FOR_INCLUDE = 0x80;

    /**
     * @var ?Closure(non-empty-string): ?string
     */
    private static ?Closure $transformation = null;

    /**
     * The context of the operation, which PHP sets.
     *
     * @var null|resource
     */
    public mixed $context = null;

    /**
     * The stream of an open file, or the stream in memory that holds the code
     * of an included file that was transformed.
     *
     * @var null|resource
     */
    private mixed $stream = null;

    /**
     * @var null|resource
     */
    private mixed $directory = null;

    /**
     * @param Closure(non-empty-string): ?string $transformation
     *
     * @throws LogicException
     */
    public static function register(Closure $transformation): void
    {
        if (self::$transformation !== null) {
            throw new LogicException('A transformation of included files is registered already');
        }

        self::$transformation = $transformation;

        self::replaceNativeWrapper();
    }

    public static function unregister(): void
    {
        if (self::$transformation === null) {
            return;
        }

        self::$transformation = null;

        stream_wrapper_restore('file');
    }

    public static function isRegistered(): bool
    {
        return self::$transformation !== null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $usePath      = ($options & STREAM_USE_PATH) !== 0;
        $reportErrors = ($options & STREAM_REPORT_ERRORS) !== 0;

        if (($options & self::OPENED_FOR_INCLUDE) !== 0 && self::$transformation !== null) {
            $realpath = self::native(static function () use ($path, $usePath): ?string
            {
                $resolved = $usePath ? stream_resolve_include_path($path) : $path;

                if (!is_string($resolved)) {
                    return null;
                }

                $realpath = realpath($resolved);

                return $realpath === false ? null : $realpath;
            });

            $transformation = self::$transformation;

            if ($realpath !== null) {
                // the transformation reads the file and may include the
                // files of its own classes with PHP's own wrapper
                $code = self::native(static fn (): ?string => $transformation($realpath));

                if ($code !== null) {
                    $stream = fopen('php://memory', 'w+b');

                    if ($stream === false) {
                        // @codeCoverageIgnoreStart
                        return false;
                        // @codeCoverageIgnoreEnd
                    }

                    fwrite($stream, $code);
                    rewind($stream);

                    $this->stream = $stream;
                    $openedPath   = $realpath;

                    return true;
                }
            }
        }

        $stream = self::native(
            fn (): mixed => $reportErrors
                ? fopen($path, $mode, $usePath, $this->context)
                : @fopen($path, $mode, $usePath, $this->context),
        );

        if (!is_resource($stream)) {
            return false;
        }

        $this->stream = $stream;

        if ($usePath) {
            $openedPath = $path;
        }

        return true;
    }

    public function stream_read(int $count): false|string
    {
        if ($count < 1) {
            return '';
        }

        return fread($this->stream(), $count);
    }

    public function stream_write(string $data): int
    {
        $written = fwrite($this->stream(), $data);

        return $written === false ? 0 : $written;
    }

    public function stream_eof(): bool
    {
        return feof($this->stream());
    }

    public function stream_tell(): int
    {
        $position = ftell($this->stream());

        return $position === false ? 0 : $position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->stream(), $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return fflush($this->stream());
    }

    public function stream_truncate(int $size): bool
    {
        return $size >= 0 && ftruncate($this->stream(), $size);
    }

    public function stream_lock(int $operation): bool
    {
        if ($operation < 1 || $operation > 7) {
            return false;
        }

        return flock($this->stream(), $operation);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->stream());
    }

    public function stream_set_option(int $option, int $first, ?int $second): bool
    {
        return match ($option) {
            STREAM_OPTION_BLOCKING     => stream_set_blocking($this->stream(), $first !== 0),
            STREAM_OPTION_READ_TIMEOUT => stream_set_timeout($this->stream(), $first, (int) $second),
            STREAM_OPTION_WRITE_BUFFER => stream_set_write_buffer($this->stream(), (int) $second) === 0,
            default                    => false,
        };
    }

    /**
     * @return false|resource
     */
    public function stream_cast(int $castAs): mixed
    {
        return $this->stream ?? false;
    }

    public function stream_close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return self::native(static function () use ($path, $option, $value): bool
        {
            if ($option === STREAM_META_TOUCH) {
                $times = is_array($value) ? $value : [];
                $mtime = $times[0] ?? null;
                $atime = $times[1] ?? null;

                return touch($path, is_int($mtime) ? $mtime : null, is_int($atime) ? $atime : null);
            }

            if ($option === STREAM_META_ACCESS) {
                return is_int($value) && chmod($path, $value);
            }

            if ($option === STREAM_META_OWNER || $option === STREAM_META_OWNER_NAME) {
                return (is_int($value) || is_string($value)) && chown($path, $value);
            }

            if ($option === STREAM_META_GROUP || $option === STREAM_META_GROUP_NAME) {
                return (is_int($value) || is_string($value)) && chgrp($path, $value);
            }

            return false;
        });
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::native(static function () use ($path, $flags): array|false
        {
            $link = ($flags & STREAM_URL_STAT_LINK) !== 0;

            if (($flags & STREAM_URL_STAT_QUIET) !== 0) {
                if (!$link && !file_exists($path)) {
                    return false;
                }

                return $link ? @lstat($path) : @stat($path);
            }

            return $link ? lstat($path) : stat($path);
        });
    }

    public function unlink(string $path): bool
    {
        return self::native(fn (): bool => unlink($path, $this->context));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(fn (): bool => rename($from, $to, $this->context));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::native(fn (): bool => mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0, $this->context));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(fn (): bool => rmdir($path, $this->context));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $directory = self::native(fn (): mixed => opendir($path, $this->context));

        if (!is_resource($directory)) {
            return false;
        }

        $this->directory = $directory;

        return true;
    }

    public function dir_readdir(): false|string
    {
        return is_resource($this->directory) ? readdir($this->directory) : false;
    }

    public function dir_rewinddir(): bool
    {
        if (!is_resource($this->directory)) {
            return false;
        }

        rewinddir($this->directory);

        return true;
    }

    public function dir_closedir(): bool
    {
        if (is_resource($this->directory)) {
            closedir($this->directory);
        }

        $this->directory = null;

        return true;
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        if (!is_resource($this->stream)) {
            throw new LogicException('The stream is not open');
        }

        return $this->stream;
    }

    /**
     * Performs an operation with PHP's own wrapper for file://.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private static function native(Closure $operation): mixed
    {
        stream_wrapper_restore('file');

        try {
            return $operation();
        } finally {
            if (self::$transformation !== null) {
                self::replaceNativeWrapper();
            }
        }
    }

    private static function replaceNativeWrapper(): void
    {
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }
}
