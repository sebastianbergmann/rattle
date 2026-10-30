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
use function sprintf;

/**
 * How a worker process is configured: which target it loads after which
 * bootstrap file, which code it instruments, and how it executes inputs. It
 * is passed to the worker as command-line arguments.
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
     * @var non-empty-string
     */
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

    /**
     * @param list<string> $arguments
     *
     * @throws ArgumentsBuilderException
     */
    public static function fromArguments(array $arguments): self
    {
        $target                 = null;
        $bootstrap              = null;
        $sources                = [];
        $timeout                = self::DEFAULT_TIMEOUT;
        $maximumNumberOfChoices = 8192;
        $memoryLimit            = null;
        $ignoreDeprecations     = false;
        $numberOfExecutions     = self::DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER;

        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];

            if ($argument === '--ignore-deprecations') {
                $ignoreDeprecations = true;

                continue;
            }

            $value = $arguments[$i + 1] ?? null;

            if ($value === null) {
                throw new ArgumentsBuilderException(sprintf('Invalid worker argument "%s"', $argument));
            }

            $i++;

            switch ($argument) {
                case '--target':
                    $target = $value;

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

                default:
                    throw new ArgumentsBuilderException(sprintf('Invalid worker argument "%s"', $argument));
            }
        }

        if ($target === null || $target === '') {
            throw new ArgumentsBuilderException('The worker needs a target');
        }

        return new self($target, $bootstrap, $sources, $timeout, $maximumNumberOfChoices, $memoryLimit, $ignoreDeprecations, $numberOfExecutions);
    }

    /**
     * @param non-empty-string $target
     * @param list<string>     $sources
     * @param positive-int     $timeout
     * @param positive-int     $maximumNumberOfChoices
     * @param positive-int     $numberOfExecutions
     */
    public function __construct(string $target, ?string $bootstrap, array $sources, int $timeout, int $maximumNumberOfChoices, ?string $memoryLimit, bool $ignoreDeprecations, int $numberOfExecutions)
    {
        $this->target                 = $target;
        $this->bootstrap              = $bootstrap;
        $this->sources                = $sources;
        $this->timeout                = $timeout;
        $this->maximumNumberOfChoices = $maximumNumberOfChoices;
        $this->memoryLimit            = $memoryLimit;
        $this->ignoreDeprecations     = $ignoreDeprecations;
        $this->numberOfExecutions     = $numberOfExecutions;
    }

    /**
     * @return non-empty-string
     */
    public function target(): string
    {
        return $this->target;
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
     * @return list<string>
     */
    public function asArguments(): array
    {
        $arguments = ['--target', $this->target];

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

        return $arguments;
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
