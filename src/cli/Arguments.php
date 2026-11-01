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

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Arguments
{
    /**
     * @var ?non-empty-string
     */
    private ?string $command;
    private ?string $target;
    private ?string $input;

    /**
     * @var ?positive-int
     */
    private ?int $jobs;
    private ?int $seed;

    /**
     * @var positive-int
     */
    private int $timeout;
    private ?string $memoryLimit;
    private ?float $maximumTime;

    /**
     * @var ?positive-int
     */
    private ?int $maximumNumberOfExecutions;

    /**
     * @var positive-int
     */
    private int $maximumNumberOfChoices;

    /**
     * @var non-negative-int
     */
    private int $maximumNumberOfShrinkingAttempts;

    /**
     * @var positive-int
     */
    private int $numberOfExecutionsPerWorker;

    /**
     * @var list<string>
     */
    private array $corpusDirectories;
    private ?string $seedsDirectory;
    private ?string $dictionary;
    private string $cacheDirectory;
    private string $findingsDirectory;
    private ?string $bootstrap;

    /**
     * @var list<string>
     */
    private array $sources;
    private bool $ignoreDeprecations;
    private bool $stopOnFinding;
    private bool $verbose;
    private bool $raw;
    private ?string $inputLog;
    private bool $help;
    private bool $version;
    private bool $phpunit;

    /**
     * @var ?non-empty-string
     */
    private ?string $phpunitConfiguration;

    /**
     * @var ?non-empty-string
     */
    private ?string $phpunitFilter;

    /**
     * @var ?non-empty-string
     */
    private ?string $sarifFile;
    private bool $json;

    /**
     * @param ?non-empty-string $command
     * @param ?positive-int     $jobs
     * @param positive-int      $timeout
     * @param ?positive-int     $maximumNumberOfExecutions
     * @param positive-int      $maximumNumberOfChoices
     * @param non-negative-int  $maximumNumberOfShrinkingAttempts
     * @param positive-int      $numberOfExecutionsPerWorker
     * @param list<string>      $corpusDirectories
     * @param list<string>      $sources
     * @param ?non-empty-string $phpunitConfiguration
     * @param ?non-empty-string $phpunitFilter
     * @param ?non-empty-string $sarifFile
     */
    public function __construct(?string $command, ?string $target, ?string $input, ?int $jobs, ?int $seed, int $timeout, ?string $memoryLimit, ?float $maximumTime, ?int $maximumNumberOfExecutions, int $maximumNumberOfChoices, int $maximumNumberOfShrinkingAttempts, int $numberOfExecutionsPerWorker, array $corpusDirectories, ?string $seedsDirectory, ?string $dictionary, string $cacheDirectory, string $findingsDirectory, ?string $bootstrap, array $sources, bool $ignoreDeprecations, bool $stopOnFinding, bool $verbose, bool $raw, ?string $inputLog, bool $help, bool $version, bool $phpunit = false, ?string $phpunitConfiguration = null, ?string $phpunitFilter = null, ?string $sarifFile = null, bool $json = false)
    {
        $this->command                          = $command;
        $this->target                           = $target;
        $this->input                            = $input;
        $this->jobs                             = $jobs;
        $this->seed                             = $seed;
        $this->timeout                          = $timeout;
        $this->memoryLimit                      = $memoryLimit;
        $this->maximumTime                      = $maximumTime;
        $this->maximumNumberOfExecutions        = $maximumNumberOfExecutions;
        $this->maximumNumberOfChoices           = $maximumNumberOfChoices;
        $this->maximumNumberOfShrinkingAttempts = $maximumNumberOfShrinkingAttempts;
        $this->numberOfExecutionsPerWorker      = $numberOfExecutionsPerWorker;
        $this->corpusDirectories                = $corpusDirectories;
        $this->seedsDirectory                   = $seedsDirectory;
        $this->dictionary                       = $dictionary;
        $this->cacheDirectory                   = $cacheDirectory;
        $this->findingsDirectory                = $findingsDirectory;
        $this->bootstrap                        = $bootstrap;
        $this->sources                          = $sources;
        $this->ignoreDeprecations               = $ignoreDeprecations;
        $this->stopOnFinding                    = $stopOnFinding;
        $this->verbose                          = $verbose;
        $this->raw                              = $raw;
        $this->inputLog                         = $inputLog;
        $this->help                             = $help;
        $this->version                          = $version;
        $this->phpunit                          = $phpunit;
        $this->phpunitConfiguration             = $phpunitConfiguration;
        $this->phpunitFilter                    = $phpunitFilter;
        $this->sarifFile                        = $sarifFile;
        $this->json                             = $json;
    }

    /**
     * @return ?non-empty-string
     */
    public function command(): ?string
    {
        return $this->command;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    public function input(): ?string
    {
        return $this->input;
    }

    /**
     * @return ?positive-int null when the number of CPU cores is to be used
     */
    public function jobs(): ?int
    {
        return $this->jobs;
    }

    /**
     * null when a random seed is to be used.
     */
    public function seed(): ?int
    {
        return $this->seed;
    }

    /**
     * @return positive-int
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    public function memoryLimit(): ?string
    {
        return $this->memoryLimit;
    }

    public function maximumTime(): ?float
    {
        return $this->maximumTime;
    }

    /**
     * @return ?positive-int
     */
    public function maximumNumberOfExecutions(): ?int
    {
        return $this->maximumNumberOfExecutions;
    }

    /**
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int
    {
        return $this->maximumNumberOfChoices;
    }

    /**
     * @return non-negative-int
     */
    public function maximumNumberOfShrinkingAttempts(): int
    {
        return $this->maximumNumberOfShrinkingAttempts;
    }

    /**
     * @return positive-int
     */
    public function numberOfExecutionsPerWorker(): int
    {
        return $this->numberOfExecutionsPerWorker;
    }

    /**
     * @return list<string>
     */
    public function corpusDirectories(): array
    {
        return $this->corpusDirectories;
    }

    public function seedsDirectory(): ?string
    {
        return $this->seedsDirectory;
    }

    public function dictionary(): ?string
    {
        return $this->dictionary;
    }

    public function cacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    public function findingsDirectory(): string
    {
        return $this->findingsDirectory;
    }

    /**
     * null when the default, vendor/autoload.php, is to be used.
     */
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

    public function ignoreDeprecations(): bool
    {
        return $this->ignoreDeprecations;
    }

    public function stopOnFinding(): bool
    {
        return $this->stopOnFinding;
    }

    public function verbose(): bool
    {
        return $this->verbose;
    }

    public function raw(): bool
    {
        return $this->raw;
    }

    /**
     * The file to which every input that is executed is written, which is
     * used to test that runs are deterministic.
     */
    public function inputLog(): ?string
    {
        return $this->inputLog;
    }

    public function help(): bool
    {
        return $this->help;
    }

    public function version(): bool
    {
        return $this->version;
    }

    /**
     * Whether the properties of a PHPUnit test suite are fuzzed rather than
     * a fuzz target.
     */
    public function phpunit(): bool
    {
        return $this->phpunit;
    }

    /**
     * @return ?non-empty-string
     */
    public function phpunitConfiguration(): ?string
    {
        return $this->phpunitConfiguration;
    }

    /**
     * @return ?non-empty-string
     */
    public function phpunitFilter(): ?string
    {
        return $this->phpunitFilter;
    }

    /**
     * The file to which the findings are written in SARIF.
     *
     * @return ?non-empty-string
     */
    public function sarifFile(): ?string
    {
        return $this->sarifFile;
    }

    /**
     * Whether "rattle status" prints the status in JSON.
     */
    public function json(): bool
    {
        return $this->json;
    }
}
