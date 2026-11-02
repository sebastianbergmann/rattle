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

use function array_column;
use function array_merge;
use function array_slice;
use function assert;
use function count;
use function ctype_digit;
use function in_array;
use function preg_match;
use function sprintf;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\CliParser\Exception as CliParserException;
use SebastianBergmann\CliParser\Parser as CliParser;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class ArgumentsBuilder
{
    private const array EXECUTION_OPTIONS = [
        'timeout=',
        'memory-limit=',
        'max-choices=',
        'bootstrap=',
        'source=',
        'ignore-deprecations',
        'opcache',
        'verbose',
    ];
    private const array COMMANDS = [
        'fuzz' => [
            'longOptions' => [
                'jobs=',
                'seed=',
                'max-time=',
                'max-executions=',
                'max-shrink-attempts=',
                'worker-executions=',
                'corpus=',
                'seeds=',
                'dictionary=',
                'cache-directory=',
                'findings-directory=',
                'stop-on-finding',
                'log-inputs=',
                'sarif=',
                'php=',
                'phpunit',
                'configuration=',
                'filter=',
            ],
            'arguments' => ['target'],
        ],
        'run' => [
            'longOptions' => [
                'raw',
                'php=',
                'phpunit',
                'configuration=',
            ],
            'arguments' => ['target', 'input'],
        ],
        'minimize' => [
            'longOptions' => [
                'max-shrink-attempts=',
                'phpunit',
                'configuration=',
            ],
            'arguments' => ['target', 'input'],
        ],
        'status' => [
            'longOptions' => [
                'cache-directory=',
                'phpunit',
                'json',
                'html=',
            ],
            'arguments' => [],
        ],
        'regress' => [
            'longOptions' => [
                'jobs=',
                'cache-directory=',
                'findings-directory=',
                'sarif=',
                'php=',
                'phpunit',
                'configuration=',
                'filter=',
            ],
            'arguments' => ['target'],
        ],
    ];

    /**
     * @param list<string> $argv
     *
     * @throws ArgumentsBuilderException
     */
    public function build(array $argv): Arguments
    {
        $longOptions = ['help', 'version'];
        $command     = null;

        if (isset($argv[1], self::COMMANDS[$argv[1]])) {
            $command     = $argv[1];
            $longOptions = array_merge($longOptions, self::EXECUTION_OPTIONS, self::COMMANDS[$command]['longOptions']);
        }

        try {
            $options = (new CliParser)->parse($argv, 'hv', $longOptions);
        } catch (CliParserException $e) {
            throw new ArgumentsBuilderException($e->getMessage(), 0, $e);
        }

        $target  = null;
        $input   = null;
        $phpunit = in_array('--phpunit', array_column($options[0], 0), true);

        if ($command !== null) {
            $positional = array_slice($options[1], 1);

            // the properties of a PHPUnit test suite are fuzzed instead of a
            // fuzz target
            $expected = $phpunit && ($command === 'fuzz' || $command === 'regress') ? [] : self::COMMANDS[$command]['arguments'];

            foreach ($expected as $position => $name) {
                if (!isset($positional[$position])) {
                    throw new ArgumentsBuilderException(sprintf('The "%s" command needs a%s', $command, match (true) {
                        $name !== 'target' => 'n input file',
                        $phpunit           => ' test ID of a property',
                        default            => ' target file',
                    }));
                }
            }

            if (count($positional) > count($expected)) {
                throw new ArgumentsBuilderException(sprintf('Too many arguments for the "%s" command', $command));
            }

            $target = $positional[0] ?? null;
            $input  = $positional[1] ?? null;
        } elseif (isset($options[1][0])) {
            throw new ArgumentsBuilderException(sprintf('Unknown command "%s"', $options[1][0]));
        }

        $jobs                             = null;
        $seed                             = null;
        $timeout                          = WorkerConfiguration::DEFAULT_TIMEOUT;
        $memoryLimit                      = null;
        $maximumTime                      = null;
        $maximumNumberOfExecutions        = null;
        $maximumNumberOfChoices           = ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES;
        $maximumNumberOfShrinkingAttempts = Minimizer::DEFAULT_MAXIMUM_NUMBER_OF_ATTEMPTS;
        $numberOfExecutionsPerWorker      = null;
        $corpusDirectories                = [];
        $seedsDirectory                   = null;
        $dictionary                       = null;
        $cacheDirectory                   = '.rattle.cache';
        $findingsDirectory                = 'fuzz/findings';
        $bootstrap                        = null;
        $sources                          = [];
        $ignoreDeprecations               = false;
        $stopOnFinding                    = false;
        $verbose                          = false;
        $raw                              = false;
        $inputLog                         = null;
        $sarifFile                        = null;
        $phpBinaries                      = [];
        $opcache                          = false;
        $json                             = false;
        $htmlFile                         = null;
        $help                             = false;
        $version                          = false;
        $phpunitConfiguration             = null;
        $phpunitFilter                    = null;

        foreach ($options[0] as [$option, $value]) {
            $value = (string) $value;

            switch ($option) {
                case '--jobs':
                    $jobs = $this->positiveInteger($option, $value);

                    break;

                case '--seed':
                    $seed = $this->integer($option, $value);

                    break;

                case '--timeout':
                    $timeout = $this->positiveInteger($option, $value);

                    break;

                case '--memory-limit':
                    $memoryLimit = $value;

                    break;

                case '--max-time':
                    $maximumTime = $this->duration($option, $value);

                    break;

                case '--max-executions':
                    $maximumNumberOfExecutions = $this->positiveInteger($option, $value);

                    break;

                case '--max-choices':
                    $maximumNumberOfChoices = $this->positiveInteger($option, $value);

                    break;

                case '--max-shrink-attempts':
                    $maximumNumberOfShrinkingAttempts = $this->nonNegativeInteger($option, $value);

                    break;

                case '--worker-executions':
                    $numberOfExecutionsPerWorker = $this->positiveInteger($option, $value);

                    break;

                case '--corpus':
                    $corpusDirectories[] = $value;

                    break;

                case '--seeds':
                    $seedsDirectory = $value;

                    break;

                case '--dictionary':
                    $dictionary = $value;

                    break;

                case '--cache-directory':
                    $cacheDirectory = $value;

                    break;

                case '--findings-directory':
                    $findingsDirectory = $value;

                    break;

                case '--bootstrap':
                    $bootstrap = $value;

                    break;

                case '--source':
                    $sources[] = $value;

                    break;

                case '--ignore-deprecations':
                    $ignoreDeprecations = true;

                    break;

                case '--opcache':
                    $opcache = true;

                    break;

                case '--stop-on-finding':
                    $stopOnFinding = true;

                    break;

                case '--verbose':
                    $verbose = true;

                    break;

                case '--raw':
                    $raw = true;

                    break;

                case '--log-inputs':
                    $inputLog = $value;

                    break;

                case '--sarif':
                    $sarifFile = $this->nonEmptyString($option, $value);

                    break;

                case '--php':
                    $phpBinaries[] = $this->nonEmptyString($option, $value);

                    break;

                case '--json':
                    $json = true;

                    break;

                case '--html':
                    $htmlFile = $this->nonEmptyString($option, $value);

                    break;

                case '--configuration':
                    $phpunitConfiguration = $this->nonEmptyString($option, $value);

                    break;

                case '--filter':
                    $phpunitFilter = $this->nonEmptyString($option, $value);

                    break;

                case 'h':
                case '--help':
                    $help = true;

                    break;

                case 'v':
                case '--version':
                    $version = true;

                    break;
            }
        }

        if (!$phpunit && ($phpunitConfiguration !== null || $phpunitFilter !== null)) {
            throw new ArgumentsBuilderException('The options --configuration and --filter can only be used with --phpunit');
        }

        if ($command === 'status' && !$phpunit) {
            throw new ArgumentsBuilderException('The "status" command shows the properties of a PHPUnit test suite and needs --phpunit');
        }

        if ($json && $htmlFile !== null) {
            throw new ArgumentsBuilderException('The options --json and --html cannot be used together');
        }

        if ($phpunit && $seedsDirectory !== null) {
            throw new ArgumentsBuilderException('The option --seeds cannot be used with --phpunit, because properties are not bytes targets');
        }

        if ($numberOfExecutionsPerWorker === null) {
            $numberOfExecutionsPerWorker = $opcache ? WorkerConfiguration::DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER_WITH_OPCACHE : WorkerConfiguration::DEFAULT_NUMBER_OF_EXECUTIONS_PER_WORKER;
        }

        return new Arguments(
            $command,
            $target,
            $input,
            $jobs,
            $seed,
            $timeout,
            $memoryLimit,
            $maximumTime,
            $maximumNumberOfExecutions,
            $maximumNumberOfChoices,
            $maximumNumberOfShrinkingAttempts,
            $numberOfExecutionsPerWorker,
            $corpusDirectories,
            $seedsDirectory,
            $dictionary,
            $cacheDirectory,
            $findingsDirectory,
            $bootstrap,
            $sources,
            $ignoreDeprecations,
            $stopOnFinding,
            $verbose,
            $raw,
            $inputLog,
            $help,
            $version,
            $phpunit,
            $phpunitConfiguration,
            $phpunitFilter,
            $sarifFile,
            $json,
            $htmlFile,
            $phpBinaries,
            $opcache,
        );
    }

    /**
     * @throws ArgumentsBuilderException
     */
    private function integer(string $option, string $value): int
    {
        if (preg_match('/\A-?\d+\z/', $value) !== 1 || (string) (int) $value !== $value) {
            throw $this->invalid($option, $value, 'an integer');
        }

        return (int) $value;
    }

    /**
     * @throws ArgumentsBuilderException
     *
     * @return non-negative-int
     */
    private function nonNegativeInteger(string $option, string $value): int
    {
        if (!ctype_digit($value) || (string) (int) $value !== $value) {
            throw $this->invalid($option, $value, 'a non-negative integer');
        }

        $integer = (int) $value;

        assert($integer >= 0);

        return $integer;
    }

    /**
     * @throws ArgumentsBuilderException
     *
     * @return non-empty-string
     */
    private function nonEmptyString(string $option, string $value): string
    {
        if ($value === '') {
            throw $this->invalid($option, $value, 'a non-empty string');
        }

        return $value;
    }

    /**
     * @throws ArgumentsBuilderException
     *
     * @return positive-int
     */
    private function positiveInteger(string $option, string $value): int
    {
        $integer = $this->nonNegativeInteger($option, $value);

        if ($integer === 0) {
            throw $this->invalid($option, $value, 'a positive integer');
        }

        return $integer;
    }

    /**
     * Parses a duration such as 90, 90s, 30m, 12h, or 2d into seconds.
     *
     * @throws ArgumentsBuilderException
     */
    private function duration(string $option, string $value): float
    {
        if (preg_match('/\A(\d+)([smhd]?)\z/', $value, $matches) !== 1 || $matches[1] === '0') {
            throw $this->invalid($option, $value, 'a positive duration such as 90s, 30m, 12h, or 2d');
        }

        return (float) $matches[1] * match ($matches[2]) {
            'm'     => 60,
            'h'     => 3600,
            'd'     => 86400,
            default => 1,
        };
    }

    private function invalid(string $option, string $value, string $expected): ArgumentsBuilderException
    {
        return new ArgumentsBuilderException(
            sprintf(
                'Invalid value "%s" for "%s", expected %s',
                $value,
                $option,
                $expected,
            ),
        );
    }
}
