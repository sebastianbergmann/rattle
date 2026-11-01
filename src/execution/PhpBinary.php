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
use function fclose;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;
use function version_compare;

/**
 * A PHP binary that runs worker processes, and its version. Worker processes
 * run rattle's own code, so the binary must be PHP 8.5 or later.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PhpBinary
{
    public const string MINIMUM_VERSION = '8.5.0';

    /**
     * @var non-empty-string
     */
    private string $path;

    /**
     * @var non-empty-string
     */
    private string $version;
    private int $major;
    private int $minor;

    /**
     * The PHP binary that runs rattle.
     */
    public static function current(): self
    {
        return new self(PHP_BINARY, PHP_VERSION, PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
    }

    /**
     * Runs the binary to determine its version.
     *
     * @param non-empty-string $path
     *
     * @throws ConfigurationException
     */
    public static function at(string $path): self
    {
        $pipes   = [];
        $process = @proc_open(
            [$path, '-r', 'echo PHP_MAJOR_VERSION, " ", PHP_MINOR_VERSION, " ", PHP_VERSION;'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new ConfigurationException(sprintf('The PHP binary "%s" cannot be run', $path));
        }

        $output = '';

        if (isset($pipes[1])) {
            $output = (string) stream_get_contents($pipes[1]);

            fclose($pipes[1]);
        }

        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        $status = proc_close($process);

        if ($status !== 0 || preg_match('/^(\d+) (\d+) (\S+)$/', trim($output), $matches) !== 1) {
            throw new ConfigurationException(sprintf('"%s" is not a PHP binary that can be run', $path));
        }

        $binary = new self($path, $matches[3], (int) $matches[1], (int) $matches[2]);

        if (version_compare($binary->version, self::MINIMUM_VERSION, '<')) {
            throw new ConfigurationException(sprintf('The PHP binary "%s" is PHP %s, but rattle needs PHP 8.5 or later', $path, $binary->version));
        }

        return $binary;
    }

    /**
     * @param non-empty-string $path
     * @param non-empty-string $version
     */
    private function __construct(string $path, string $version, int $major, int $minor)
    {
        $this->path    = $path;
        $this->version = $version;
        $this->major   = $major;
        $this->minor   = $minor;
    }

    /**
     * @return non-empty-string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The version of PHP, PHP_VERSION of the binary.
     *
     * @return non-empty-string
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * The major and minor version, for instance PHP 8.6, which tells versions
     * apart without depending on their patch releases.
     *
     * @return non-empty-string
     */
    public function label(): string
    {
        return sprintf('PHP %d.%d', $this->major, $this->minor);
    }
}
