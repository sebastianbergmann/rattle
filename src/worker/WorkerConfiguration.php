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

use function count;
use function ctype_digit;
use function in_array;
use function sprintf;

/**
 * How a worker process is configured: which target it loads after which
 * bootstrap file, which code it instruments, and how it executes inputs. It
 * is passed to the worker as command-line arguments.
 *
 * When the properties of a PHPUnit test suite are fuzzed, the target is the
 * test ID of a property. A worker without a target lists the test IDs of the
 * properties and then executes inputs of all of them, each of which names
 * its property.
 *
 * A worker can also load several target files, of fuzz targets that are
 * fuzzed together. It describes all their targets and then executes inputs
 * of all of them, each of which names its target.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class WorkerConfiguration
{
    public const int DEFAULT_TIMEOUT                         = 1;
    public const int DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER = 10000;

    /**
     * With OPcache and its JIT, a worker process takes longer to start,
     * because the JIT compiles the functions of every file that it loads, so
     * it is replaced less often.
     */
    public const int DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER_WITH_OPCACHE = 100000;
    private string $target;
    private ?string $bootstrap;

    /**
     * @var list<string>
     */
    private array $sources;

    /**
     * @var positive-int
     */
    private int $timeout;

    /**
     * @var positive-int
     */
    private int $maximumNumberOfChoices;
    private ?string $memoryLimit;
    private bool $ignoreDeprecations;

    /**
     * @var positive-int
     */
    private int $numberOfExecutions;
    private ?PhpunitTestSuite $phpunit;
    private bool $opcache;

    /**
     * @var list<string>
     */
    private array $targets;

    /**
     * @param list<string> $arguments
     *
     * @throws ArgumentsBuilderException
     */
    public static function fromArguments(array $arguments): self
    {
        $targets                = [];
        $bootstrap              = null;
        $sources                = [];
        $timeout                = self::DEFAULT_TIMEOUT;
        $maximumNumberOfChoices = 8192;
        $memoryLimit            = null;
        $ignoreDeprecations     = false;
        $numberOfExecutions     = self::DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER;
        $phpunit                = false;
        $phpunitConfiguration   = null;
        $phpunitFilter          = null;
        $opcache                = false;

        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];

            if ($argument === '--ignore-deprecations') {
                $ignoreDeprecations = true;

                continue;
            }

            if ($argument === '--phpunit') {
                $phpunit = true;

                continue;
            }

            if ($argument === '--opcache') {
                $opcache = true;

                continue;
            }

            $value = $arguments[$i + 1] ?? null;

            if ($value === null) {
                throw new ArgumentsBuilderException(sprintf('Invalid worker argument "%s"', $argument));
            }

            $i++;

            switch ($argument) {
                case '--target':
                    $targets[] = $value;

                    break;

                case '--bootstrap':
                    $bootstrap = $value;

                    break;

                case '--source':
                    $sources[] = $value;

                    break;

                case '--timeout':
                    $timeout = self::positiveInteger($argument, $value);

                    break;

                case '--max-choices':
                    $maximumNumberOfChoices = self::positiveInteger($argument, $value);

                    break;

                case '--memory-limit':
                    $memoryLimit = $value;

                    break;

                case '--worker-executions':
                    $numberOfExecutions = self::positiveInteger($argument, $value);

                    break;

                case '--phpunit-configuration':
                    $phpunitConfiguration = self::nonEmptyString($argument, $value);

                    break;

                case '--phpunit-filter':
                    $phpunitFilter = self::nonEmptyString($argument, $value);

                    break;

                default:
                    throw new ArgumentsBuilderException(sprintf('Invalid worker argument "%s"', $argument));
            }
        }

        if ($phpunit ? count($targets) > 1 : ($targets === [] || in_array('', $targets, true))) {
            throw new ArgumentsBuilderException($phpunit ? 'The worker serves one property or all of them' : 'The worker needs a target');
        }

        return new self(
            count($targets) === 1 ? $targets[0] : '',
            $bootstrap,
            $sources,
            $timeout,
            $maximumNumberOfChoices,
            $memoryLimit,
            $ignoreDeprecations,
            $numberOfExecutions,
            $phpunit ? new PhpunitTestSuite($phpunitConfiguration, $phpunitFilter) : null,
            $opcache,
            count($targets) > 1 ? $targets : [],
        );
    }

    /**
     * @param string       $target                 the target file, or the test ID of a property; empty only when a worker lists the properties of a PHPUnit test suite, or loads several target files
     * @param list<string> $sources
     * @param positive-int $timeout
     * @param positive-int $maximumNumberOfChoices
     * @param positive-int $numberOfExecutions
     * @param bool         $opcache                whether the worker process runs with OPcache and its JIT
     * @param list<string> $targets                the target files of a worker that loads several of them, when the target is empty
     */
    public function __construct(string $target, ?string $bootstrap, array $sources, int $timeout, int $maximumNumberOfChoices, ?string $memoryLimit, bool $ignoreDeprecations, int $numberOfExecutions, ?PhpunitTestSuite $phpunit = null, bool $opcache = false, array $targets = [])
    {
        $this->target                 = $target;
        $this->bootstrap              = $bootstrap;
        $this->sources                = $sources;
        $this->timeout                = $timeout;
        $this->maximumNumberOfChoices = $maximumNumberOfChoices;
        $this->memoryLimit            = $memoryLimit;
        $this->ignoreDeprecations     = $ignoreDeprecations;
        $this->numberOfExecutions     = $numberOfExecutions;
        $this->phpunit                = $phpunit;
        $this->opcache                = $opcache;
        $this->targets                = $targets;
    }

    /**
     * The configuration of a worker that executes the inputs of another
     * target with the same settings: another property of the same PHPUnit
     * test suite, for instance, or one of the target files of a worker that
     * loads several of them.
     */
    public function withTarget(string $target): self
    {
        return new self(
            $target,
            $this->bootstrap,
            $this->sources,
            $this->timeout,
            $this->maximumNumberOfChoices,
            $this->memoryLimit,
            $this->ignoreDeprecations,
            $this->numberOfExecutions,
            $this->phpunit,
            $this->opcache,
        );
    }

    /**
     * The configuration of a worker that loads the target files and executes
     * the inputs of all their targets, with the same settings. With one
     * target file, the worker loads that one.
     *
     * @param non-empty-list<string> $targets
     */
    public function withTargets(array $targets): self
    {
        if (count($targets) === 1) {
            return $this->withTarget($targets[0]);
        }

        return new self(
            '',
            $this->bootstrap,
            $this->sources,
            $this->timeout,
            $this->maximumNumberOfChoices,
            $this->memoryLimit,
            $this->ignoreDeprecations,
            $this->numberOfExecutions,
            $this->phpunit,
            $this->opcache,
            $targets,
        );
    }

    /**
     * The target file, or the test ID of a property, which is empty when a
     * worker lists the properties of a PHPUnit test suite, or loads several
     * target files.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * The target files of a worker that loads several of them, or an empty
     * list.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * The PHPUnit test suite whose properties are fuzzed, null when a fuzz
     * target is fuzzed.
     */
    public function phpunit(): ?PhpunitTestSuite
    {
        return $this->phpunit;
    }

    public function bootstrap(): ?string
    {
        return $this->bootstrap;
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return positive-int
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int
    {
        return $this->maximumNumberOfChoices;
    }

    public function memoryLimit(): ?string
    {
        return $this->memoryLimit;
    }

    public function ignoreDeprecations(): bool
    {
        return $this->ignoreDeprecations;
    }

    /**
     * The number of inputs after which a worker exits, so that state that
     * leaks through static properties is limited.
     *
     * @return positive-int
     */
    public function numberOfExecutions(): int
    {
        return $this->numberOfExecutions;
    }

    /**
     * Whether the worker process runs with OPcache and its JIT.
     */
    public function opcache(): bool
    {
        return $this->opcache;
    }

    /**
     * The settings that PHP is started with for the worker process: OPcache
     * and its JIT, when they are turned on, and otherwise the settings of
     * php.ini. OPcache caches instrumented code under the path of the file
     * that it was instrumented from, so its file cache is turned off either
     * way: instrumented code must never be stored where another process could
     * load it.
     *
     * The JIT compiles the functions of a file when the file is loaded. The
     * tracing JIT compiles the code that is executed often while it is
     * executed, so an execution during which it compiles uses more memory
     * than one during which it does not: whether an input exhausts the memory
     * would depend on the inputs that the worker process executed before.
     *
     * @return list<string>
     */
    public function phpSettings(): array
    {
        $settings = ['-d', 'opcache.file_cache=', '-d', 'opcache.file_cache_only=0'];

        if ($this->opcache) {
            $settings = [
                ...$settings,
                '-d', 'opcache.enable=1',
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.jit=function',
                '-d', 'opcache.jit_buffer_size=128M',
            ];
        }

        return $settings;
    }

    /**
     * @return list<string>
     */
    public function asArguments(): array
    {
        $arguments = [];

        foreach ($this->targets === [] && $this->target !== '' ? [$this->target] : $this->targets as $target) {
            $arguments[] = '--target';
            $arguments[] = $target;
        }

        if ($this->bootstrap !== null) {
            $arguments[] = '--bootstrap';
            $arguments[] = $this->bootstrap;
        }

        foreach ($this->sources as $source) {
            $arguments[] = '--source';
            $arguments[] = $source;
        }

        $arguments[] = '--timeout';
        $arguments[] = (string) $this->timeout;
        $arguments[] = '--max-choices';
        $arguments[] = (string) $this->maximumNumberOfChoices;

        if ($this->memoryLimit !== null) {
            $arguments[] = '--memory-limit';
            $arguments[] = $this->memoryLimit;
        }

        if ($this->ignoreDeprecations) {
            $arguments[] = '--ignore-deprecations';
        }

        $arguments[] = '--worker-executions';
        $arguments[] = (string) $this->numberOfExecutions;

        if ($this->opcache) {
            $arguments[] = '--opcache';
        }

        if ($this->phpunit !== null) {
            $arguments[] = '--phpunit';

            if ($this->phpunit->configurationFile() !== null) {
                $arguments[] = '--phpunit-configuration';
                $arguments[] = $this->phpunit->configurationFile();
            }

            if ($this->phpunit->filter() !== null) {
                $arguments[] = '--phpunit-filter';
                $arguments[] = $this->phpunit->filter();
            }
        }

        return $arguments;
    }

    /**
     * @throws ArgumentsBuilderException
     *
     * @return non-empty-string
     */
    private static function nonEmptyString(string $argument, string $value): string
    {
        if ($value === '') {
            throw new ArgumentsBuilderException(sprintf('Invalid value "" for worker argument "%s"', $argument));
        }

        return $value;
    }

    /**
     * @throws ArgumentsBuilderException
     *
     * @return positive-int
     */
    private static function positiveInteger(string $argument, string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new ArgumentsBuilderException(sprintf('Invalid value "%s" for worker argument "%s"', $value, $argument));
        }

        return (int) $value;
    }
}
