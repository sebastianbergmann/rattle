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

use const PHP_EOL;
use const PHP_INT_MAX;
use const SIGINT;
use const STDOUT;
use function count;
use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function implode;
use function is_dir;
use function is_file;
use function is_resource;
use function max;
use function microtime;
use function mkdir;
use function pcntl_async_signals;
use function pcntl_signal;
use function printf;
use function random_int;
use function round;
use function sprintf;
use function stream_isatty;
use function unlink;
use Closure;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

/**
 * Fuzzes a target until a limit is reached or Ctrl+C is pressed.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class FuzzCommand implements Command
{
    public function run(Arguments $arguments): int
    {
        $setup  = new Setup;
        $stderr = $setup->stderr($arguments);
        $jobs   = $arguments->jobs() ?? (new ProcessorCount)->count();
        $seed   = $arguments->seed() ?? random_int(0, PHP_INT_MAX);

        try {
            $configuration = $setup->workerConfiguration($arguments);
            $dictionary    = [];

            if ($arguments->dictionary() !== null) {
                $dictionary = (new DictionaryParser)->parseFile($arguments->dictionary());
            }
        } catch (ConfigurationException|InvalidDictionaryException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $interrupted = false;

        pcntl_async_signals(true);
        pcntl_signal(
            SIGINT,
            static function () use (&$interrupted): void
            {
                $interrupted = true;
            },
        );

        $isInterrupted = static fn (): bool => $interrupted;
        $phpunit       = $configuration->phpunit();

        if ($phpunit === null) {
            return $this->fuzz($arguments, $configuration, $this->executionOptions($arguments) . $arguments->target(), $arguments->maximumTime(), $stderr, $jobs, $seed, $dictionary, $isInterrupted);
        }

        try {
            $properties = (new PropertyLister)->list($configuration);
        } catch (WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        if ($properties === []) {
            print 'The PHPUnit test suite has no properties' . ($phpunit->filter() === null ? '' : ' that match the filter') . PHP_EOL;

            return 2;
        }

        // the inputs of a corpus directory and the log of the inputs belong
        // to one target
        if (count($properties) > 1 && ($arguments->corpusDirectories() !== [] || $arguments->inputLog() !== null)) {
            print 'The options --corpus and --log-inputs can only be used with --phpunit when the filter selects one property' . PHP_EOL;

            return 2;
        }

        // the properties are fuzzed one after another and share the time of
        // --max-time: every property gets an equal share of the time that is
        // left, so a property that ends early leaves its time to the ones
        // after it, while --max-executions applies to every property
        $deadline = null;
        $result   = 0;

        if ($arguments->maximumTime() !== null) {
            $deadline = microtime(true) + $arguments->maximumTime();
        }

        foreach ($properties as $index => $property) {
            if ($interrupted) {
                break;
            }

            $maximumTime = null;

            if ($deadline !== null) {
                $maximumTime = ($deadline - microtime(true)) / (count($properties) - $index);

                if ($maximumTime <= 0) {
                    printf(
                        PHP_EOL . 'The time of --max-time is up, %d of %d properties were not fuzzed.' . PHP_EOL,
                        count($properties) - $index,
                        count($properties),
                    );

                    break;
                }
            }

            if ($index > 0) {
                print PHP_EOL;
            }

            $status = $this->fuzz($arguments, $configuration->withTarget($property), $this->executionOptions($arguments) . $this->reproduction($phpunit, $property), $maximumTime, $stderr, $jobs, $seed, $dictionary, $isInterrupted);

            if ($status === 2) {
                return 2;
            }

            $result = max($result, $status);
        }

        return $result;
    }

    /**
     * @param string                      $reproduction  what is passed to "rattle run" to execute an input of the target again
     * @param ?float                      $maximumTime   in seconds, null for no time limit
     * @param ?Closure(int, string): void $stderr
     * @param positive-int                $jobs
     * @param list<non-empty-string>      $tokens        the tokens of the dictionary file
     * @param Closure(): bool             $isInterrupted
     *
     * @return 0|1|2
     */
    private function fuzz(Arguments $arguments, WorkerConfiguration $configuration, string $reproduction, ?float $maximumTime, ?Closure $stderr, int $jobs, int $seed, array $tokens, Closure $isInterrupted): int
    {
        $executor = new ProcessExecutor($configuration, $jobs, $stderr);

        try {
            $target = $executor->start();
        } catch (WorkerException $e) {
            $executor->shutdown();

            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $name = $target->name();

        if ($configuration->phpunit() !== null) {
            $name = PropertyDirectory::nameOf($target->name());
        }

        $corpusDirectory   = $arguments->cacheDirectory() . '/' . $name . '/corpus';
        $findingsDirectory = $arguments->findingsDirectory() . '/' . $name;
        $loader            = new InputLoader;
        $initialInputs     = $loader->choiceSequencesIn($corpusDirectory);
        $numberOfStored    = count($initialInputs);

        foreach ($arguments->corpusDirectories() as $directory) {
            if (!is_dir($directory)) {
                $executor->shutdown();

                printf('The corpus directory "%s" does not exist' . PHP_EOL, $directory);

                return 2;
            }

            foreach ($loader->choiceSequencesIn($directory) as $input) {
                $initialInputs[] = $input;
            }
        }

        if ($arguments->seedsDirectory() !== null) {
            if (!$target->isBytesTarget() || !is_dir($arguments->seedsDirectory())) {
                $executor->shutdown();

                printf(
                    $target->isBytesTarget() ? 'The seeds directory "%s" does not exist' . PHP_EOL : 'Raw seed files from "%s" can only be imported for a bytes target' . PHP_EOL,
                    $arguments->seedsDirectory(),
                );

                return 2;
            }

            foreach ($loader->rawFilesIn($arguments->seedsDirectory(), $target->maximumLength()) as $input) {
                $initialInputs[] = $input;
            }
        }

        $status               = new StatusLine(STDOUT, stream_isatty(STDOUT), static fn (): float => microtime(true));
        $minimizationExecutor = new ProcessExecutor($configuration, 1, $stderr);
        $store                = new FindingStore($findingsDirectory);
        $findings             = new FindingProcessor(
            new Minimizer($minimizationExecutor, $arguments->maximumNumberOfShrinkingAttempts(), $isInterrupted),
            $store,
            $target,
            $reproduction,
            $seed,
            static function (Minimization $minimization, string $directory) use ($status): void
            {
                $notes = [];

                if ($minimization->isFlaky()) {
                    $notes[] = 'flaky, not minimized';
                } elseif (!$minimization->minimalInputReproduced()) {
                    $notes[] = 'the minimal input did not fail again, kept the input as found';
                }

                $status->finding(
                    sprintf(
                        'Finding: %s%s' . PHP_EOL . '         %s',
                        $minimization->failure()->describe(),
                        $notes === [] ? '' : ' (' . implode(', ', $notes) . ')',
                        $directory,
                    ),
                );
            },
        );

        $this->printHeader($target, $configuration->phpunit() !== null, $maximumTime, $seed, $jobs, $configuration, $corpusDirectory, $numberOfStored, $findingsDirectory, $findings->numberOfKnownFindings(), $arguments->maximumNumberOfChoices());

        foreach ($loader->warnings() as $warning) {
            print 'Warning: ' . $warning . PHP_EOL;
        }

        $log = null;

        if ($arguments->inputLog() !== null) {
            $log = fopen($arguments->inputLog(), 'w');
        }

        $engine = new Engine(
            $executor,
            $corpus     = new Corpus($corpusDirectory),
            $dictionary = new Dictionary($tokens),
            Mutator::create($corpus, $dictionary, $target->maximumNumberOfChoices()),
            $findings,
            new EngineConfiguration($seed, $arguments->maximumNumberOfExecutions(), $maximumTime, $arguments->stopOnFinding()),
            $status->update(...),
            is_resource($log) ? static function (Job $job) use ($log): void
            {
                fwrite($log, $job->isReplay() ? 'replay ' . implode(',', $job->choices()) . "\n" : 'generate ' . $job->seed() . "\n");
            } : null,
            $isInterrupted,
        );

        try {
            $statistics = $engine->run($initialInputs);
        } catch (CorpusException|FindingException|ProtocolException|WorkerException $e) {
            print PHP_EOL . $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            $executor->shutdown();
            $minimizationExecutor->shutdown();

            if (is_resource($log)) {
                fclose($log);
            }
        }

        $status->finish($statistics);

        printf(
            PHP_EOL . '%s%d new finding%s, %d known finding%s found again.' . PHP_EOL,
            $isInterrupted() ? 'Interrupted. ' : '',
            $statistics->findings,
            $statistics->findings === 1 ? '' : 's',
            $statistics->duplicateFindings,
            $statistics->duplicateFindings === 1 ? ' was' : 's were',
        );

        $this->writeFoundTokens($arguments->cacheDirectory() . '/' . $name, $dictionary);

        return $statistics->findings > 0 ? 1 : 0;
    }

    /**
     * Writes the tokens that were found in the comparisons of the code that
     * was instrumented to a dictionary file next to the corpus, where they
     * can be looked at and passed with --dictionary. The dictionary file of an
     * earlier run is removed when no tokens were found, so that it does not
     * outlive the comparisons it was found in.
     */
    private function writeFoundTokens(string $directory, Dictionary $dictionary): void
    {
        $path   = $directory . '/dictionary.txt';
        $tokens = $dictionary->foundTokens();

        if ($tokens === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        $contents = (new DictionaryWriter)->write('the constant operands of comparisons that rattle found in the code that it instrumented', $tokens);

        if ((!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) ||
            @file_put_contents($path, $contents) === false) {
            printf('Warning: Cannot write the tokens that were found in comparisons to "%s"' . PHP_EOL, $path);

            return;
        }

        printf(
            '%d token%s found in comparisons %s written to %s' . PHP_EOL,
            count($tokens),
            count($tokens) === 1 ? ' that was' : 's that were',
            count($tokens) === 1 ? 'was' : 'were',
            $path,
        );
    }

    /**
     * The options that change how an input is executed, as "rattle run" needs
     * them to execute an input again in the same way.
     */
    private function executionOptions(Arguments $arguments): string
    {
        $options = '';

        if ($arguments->bootstrap() !== null) {
            $options .= '--bootstrap ' . escapeshellarg($arguments->bootstrap()) . ' ';
        }

        if ($arguments->timeout() !== WorkerConfiguration::DEFAULT_TIMEOUT) {
            $options .= '--timeout ' . $arguments->timeout() . ' ';
        }

        if ($arguments->memoryLimit() !== null) {
            $options .= '--memory-limit ' . escapeshellarg($arguments->memoryLimit()) . ' ';
        }

        if ($arguments->maximumNumberOfChoices() !== ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES) {
            $options .= '--max-choices ' . $arguments->maximumNumberOfChoices() . ' ';
        }

        if ($arguments->ignoreDeprecations()) {
            $options .= '--ignore-deprecations ';
        }

        return $options;
    }

    /**
     * The arguments of "rattle run" that execute an input of a property again.
     *
     * @param non-empty-string $property
     *
     * @return non-empty-string
     */
    private function reproduction(PhpunitTestSuite $phpunit, string $property): string
    {
        $reproduction = '--phpunit';

        if ($phpunit->configurationFile() !== null) {
            $reproduction .= ' --configuration ' . escapeshellarg($phpunit->configurationFile());
        }

        return $reproduction . ' ' . escapeshellarg($property);
    }

    /**
     * @param ?float $maximumTime the share of a property of the time of --max-time
     */
    private function printHeader(TargetDescription $target, bool $property, ?float $maximumTime, int $seed, int $jobs, WorkerConfiguration $configuration, string $corpusDirectory, int $numberOfStoredInputs, string $findingsDirectory, int $numberOfKnownFindings, int $maximumNumberOfChoices): void
    {
        printf(
            'Target:   %s (%s)' . PHP_EOL .
            'Seed:     %d' . PHP_EOL .
            'Jobs:     %d' . PHP_EOL .
            'Sources:  %s' . PHP_EOL .
            'Corpus:   %s (%d stored input%s)' . PHP_EOL .
            'Findings: %s (%d known)' . PHP_EOL,
            $target->name(),
            $property ? 'property' : ($target->isBytesTarget() ? sprintf('bytes, at most %d', $target->maximumLength()) : 'structured'),
            $seed,
            $jobs,
            $configuration->sources() === [] ? 'none, no code is instrumented' : implode(', ', $configuration->sources()),
            $corpusDirectory,
            $numberOfStoredInputs,
            $numberOfStoredInputs === 1 ? '' : 's',
            $findingsDirectory,
            $numberOfKnownFindings,
        );

        if ($property && $maximumTime !== null) {
            printf('Time:     at most %s of --max-time' . PHP_EOL, StatusLine::duration(round($maximumTime)));
        }

        if ($target->maximumNumberOfChoices() > $maximumNumberOfChoices) {
            printf(
                'The maximum number of choices was raised from %d to %d, which the target needs.' . PHP_EOL,
                $maximumNumberOfChoices,
                $target->maximumNumberOfChoices(),
            );
        }

        print PHP_EOL;
    }
}
