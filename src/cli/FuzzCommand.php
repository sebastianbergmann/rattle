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
use function array_keys;
use function count;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function getcwd;
use function implode;
use function is_dir;
use function is_file;
use function is_resource;
use function max;
use function microtime;
use function mkdir;
use function number_format;
use function pcntl_async_signals;
use function pcntl_signal;
use function printf;
use function random_int;
use function sprintf;
use function stream_isatty;
use function strlen;
use function unlink;
use Closure;
use JsonException;
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

        return $this->fuzzTestSuite($arguments, $configuration, $phpunit, $stderr, $jobs, $seed, $dictionary, $isInterrupted);
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
            $this->findingListener($status, null),
        );

        $this->printHeader($target, $seed, $jobs, $configuration, $corpusDirectory, $numberOfStored, $findingsDirectory, $findings->numberOfKnownFindings(), $arguments->maximumNumberOfChoices());

        foreach ($loader->warnings() as $warning) {
            print 'Warning: ' . $warning . PHP_EOL;
        }

        $log    = null;
        $report = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());

        if ($arguments->inputLog() !== null) {
            $log = fopen($arguments->inputLog(), 'w');
        }

        $engine = new Engine(
            $executor,
            $corpus     = new Corpus($corpusDirectory),
            $dictionary = new Dictionary($tokens, Sentinels::tokens()),
            Mutator::create($corpus, $dictionary, $target->maximumNumberOfChoices()),
            $report === null ? $findings : new FindingRecorder($findings, $report, null),
            new EngineConfiguration($seed, $arguments->maximumNumberOfExecutions(), $maximumTime, $arguments->stopOnFinding()),
            $status->update(...),
            $this->inputLogger($log),
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

        print PHP_EOL;

        $this->printSummary($statistics, $isInterrupted());

        $written = $this->writeFoundTokens($arguments->cacheDirectory() . '/' . $name, $dictionary);

        if ($written !== null) {
            printf(
                '%d token%s found in comparisons %s written to %s' . PHP_EOL,
                $written,
                $written === 1 ? ' that was' : 's that were',
                $written === 1 ? 'was' : 'were',
                $arguments->cacheDirectory() . '/' . $name . '/dictionary.txt',
            );
        }

        if (!(new Setup)->writeSarifReport($report, $arguments->sarifFile())) {
            return 2;
        }

        return $statistics->findings > 0 ? 1 : 0;
    }

    /**
     * Fuzzes the properties of a PHPUnit test suite together, with one pool
     * of workers that serves all of them (see TargetScheduler). Every
     * property has an engine, a corpus, and findings of its own, while the
     * dictionary is shared, because the constant operands of comparisons are
     * found in the code that all properties exercise.
     *
     * @param ?Closure(int, string): void $stderr
     * @param positive-int                $jobs
     * @param list<non-empty-string>      $tokens        the tokens of the dictionary file
     * @param Closure(): bool             $isInterrupted
     *
     * @return 0|1|2
     */
    private function fuzzTestSuite(Arguments $arguments, WorkerConfiguration $configuration, PhpunitTestSuite $phpunit, ?Closure $stderr, int $jobs, int $seed, array $tokens, Closure $isInterrupted): int
    {
        $executor = new ProcessExecutor($configuration->withTarget(''), $jobs, $stderr);

        try {
            $properties = $executor->properties() ?? [];
            $target     = $executor->target();
        } catch (WorkerException $e) {
            $executor->shutdown();

            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $error = null;

        if ($properties === []) {
            $error = 'The PHPUnit test suite has no properties' . ($phpunit->filter() === null ? '' : ' that match the filter');
        } elseif (count($properties) > 1 && ($arguments->corpusDirectories() !== [] || $arguments->inputLog() !== null)) {
            // the inputs of a corpus directory and the log of the inputs
            // belong to one target
            $error = 'The options --corpus and --log-inputs can only be used with --phpunit when the filter selects one property';
        }

        foreach ($arguments->corpusDirectories() as $directory) {
            if ($error === null && !is_dir($directory)) {
                $error = sprintf('The corpus directory "%s" does not exist', $directory);
            }
        }

        if ($error !== null) {
            $executor->shutdown();

            print $error . PHP_EOL;

            return 2;
        }

        $status                = new StatusLine(STDOUT, stream_isatty(STDOUT), static fn (): float => microtime(true));
        $report                = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());
        $dictionary            = new Dictionary($tokens, Sentinels::tokens());
        $ids                   = new JobIds;
        $loader                = new InputLoader;
        $log                   = $arguments->inputLog() !== null ? fopen($arguments->inputLog(), 'w') : null;
        $engines               = [];
        $histories             = [];
        $minimizationExecutors = [];
        $numberOfStoredInputs  = 0;
        $numberOfKnownFindings = 0;

        foreach ($properties as $index => $property) {
            $name            = PropertyDirectory::nameOf($property);
            $corpusDirectory = $arguments->cacheDirectory() . '/' . $name . '/corpus';
            $initialInputs   = $loader->choiceSequencesIn($corpusDirectory);

            $numberOfStoredInputs += count($initialInputs);

            foreach ($arguments->corpusDirectories() as $directory) {
                foreach ($loader->choiceSequencesIn($directory) as $input) {
                    $initialInputs[] = $input;
                }
            }

            // the worker that minimizes the inputs of a property is only
            // started when the property has a finding
            $minimizationExecutors[] = $minimizationExecutor = new ProcessExecutor($configuration->withTarget($property), 1, $stderr);

            $findings = new FindingProcessor(
                new Minimizer($minimizationExecutor, $arguments->maximumNumberOfShrinkingAttempts(), $isInterrupted),
                new FindingStore($arguments->findingsDirectory() . '/' . $name),
                new TargetDescription($property, null, $target->maximumNumberOfChoices()),
                $this->executionOptions($arguments) . $this->reproduction($phpunit, $property),
                $seed,
                $this->findingListener($status, $property),
            );

            $numberOfKnownFindings += $findings->numberOfKnownFindings();

            $corpus = new Corpus($corpusDirectory);

            $engines[$property] = new Engine(
                $executor,
                $corpus,
                $dictionary,
                Mutator::create($corpus, $dictionary, $target->maximumNumberOfChoices()),
                $report === null ? $findings : new FindingRecorder($findings, $report, $property),
                // every property draws from a random number generator of its
                // own, so that the properties are not mutated in lockstep
                new EngineConfiguration(Engine::seedOf($seed, $index), $arguments->maximumNumberOfExecutions(), null, $arguments->stopOnFinding(), EngineConfiguration::DEFAULT_BATCH_SIZE, $property),
                null,
                $this->inputLogger($log),
                $isInterrupted,
                $ids,
            );

            $engines[$property]->start($initialInputs);

            $histories[$property] = TargetHistory::fromFile($this->historyFile($arguments, $property), $property);
        }

        printf(
            'Target:   PHPUnit test suite (%d propert%s)' . PHP_EOL .
            'Seed:     %d' . PHP_EOL .
            'Jobs:     %d' . PHP_EOL .
            'Sources:  %s' . PHP_EOL .
            'Corpus:   %s (%d stored input%s)' . PHP_EOL .
            'Findings: %s (%d known)' . PHP_EOL . PHP_EOL,
            count($properties),
            count($properties) === 1 ? 'y' : 'ies',
            $seed,
            $jobs,
            $configuration->sources() === [] ? 'none, no code is instrumented' : implode(', ', $configuration->sources()),
            $arguments->cacheDirectory(),
            $numberOfStoredInputs,
            $numberOfStoredInputs === 1 ? '' : 's',
            $arguments->findingsDirectory(),
            $numberOfKnownFindings,
        );

        foreach ($loader->warnings() as $warning) {
            print 'Warning: ' . $warning . PHP_EOL;
        }

        $start     = microtime(true);
        $scheduler = new TargetScheduler(
            $executor,
            $engines,
            $seed,
            $arguments->maximumTime(),
            $isInterrupted,
            function () use ($status, $engines, $dictionary, $start): void
            {
                $status->update($this->totals($engines, $dictionary, $start), $this->activeProperties($engines));
            },
            $histories,
        );

        try {
            $scheduler->run();
        } catch (CorpusException|FindingException|ProtocolException|WorkerException $e) {
            print PHP_EOL . $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            $executor->shutdown();

            foreach ($minimizationExecutors as $minimizationExecutor) {
                $minimizationExecutor->shutdown();
            }

            if (is_resource($log)) {
                fclose($log);
            }
        }

        $totals = $this->totals($engines, $dictionary, $start);

        $status->finish($totals, $this->activeProperties($engines));

        // what the run learned about the properties is kept for later runs
        foreach ($scheduler->histories() as $property => $history) {
            $file = $this->historyFile($arguments, $property);

            if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o777, true)) || !$history->write($file)) {
                printf('Warning: Cannot write "%s"' . PHP_EOL, $file);
            }
        }

        $this->writeSuiteStatus($arguments->cacheDirectory());

        print PHP_EOL;

        $this->printTable($engines, $scheduler->histories());
        $this->printSummary($totals, $isInterrupted());

        $written = null;

        foreach ($properties as $property) {
            $written = $this->writeFoundTokens($arguments->cacheDirectory() . '/' . PropertyDirectory::nameOf($property), $dictionary);
        }

        if ($written !== null) {
            printf(
                '%d token%s found in comparisons %s written to the dictionary.txt of every property.' . PHP_EOL,
                $written,
                $written === 1 ? ' that was' : 's that were',
                $written === 1 ? 'was' : 'were',
            );
        }

        if (!(new Setup)->writeSarifReport($report, $arguments->sarifFile())) {
            return 2;
        }

        return $totals->findings > 0 ? 1 : 0;
    }

    /**
     * The file in which what rattle knows about a property from the runs that
     * fuzzed it is kept, next to its corpus.
     *
     * @param non-empty-string $property
     */
    private function historyFile(Arguments $arguments, string $property): string
    {
        return $arguments->cacheDirectory() . '/' . PropertyDirectory::nameOf($property) . '/statistics.json';
    }

    /**
     * Shows how many findings were found, and how the corpus was reduced.
     */
    private function printSummary(Statistics $statistics, bool $interrupted): void
    {
        printf(
            '%s%d new finding%s, %d known finding%s found again.' . PHP_EOL,
            $interrupted ? 'Interrupted. ' : '',
            $statistics->findings,
            $statistics->findings === 1 ? '' : 's',
            $statistics->duplicateFindings,
            $statistics->duplicateFindings === 1 ? ' was' : 's were',
        );

        if ($statistics->reducedEntries > 0) {
            printf(
                '%d corpus entr%s replaced with %s that %s fewer choices and produce%s the same features.' . PHP_EOL,
                $statistics->reducedEntries,
                $statistics->reducedEntries === 1 ? 'y was' : 'ies were',
                $statistics->reducedEntries === 1 ? 'an input' : 'inputs',
                $statistics->reducedEntries === 1 ? 'has' : 'have',
                $statistics->reducedEntries === 1 ? 's' : '',
            );
        }

        if ($statistics->removedInputs > 0) {
            printf(
                '%d stored input%s that produce%s no feature that a smaller input does not produce %s removed from the corpus.' . PHP_EOL,
                $statistics->removedInputs,
                $statistics->removedInputs === 1 ? '' : 's',
                $statistics->removedInputs === 1 ? 's' : '',
                $statistics->removedInputs === 1 ? 'was' : 'were',
            );
        }
    }

    /**
     * Shows how fuzzing went for every property in the run, how often it was
     * executed in all runs, and how long ago it last discovered features.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     * @param array<non-empty-string, TargetHistory>    $histories
     */
    private function printTable(array $engines, array $histories): void
    {
        $width = strlen('Property');
        $now   = microtime(true);

        foreach (array_keys($engines) as $property) {
            $width = max($width, strlen($property));
        }

        $format = '%-' . $width . 's  %10s  %8s  %6s  %8s  %10s  %s' . PHP_EOL;

        printf($format, 'Property', 'Executions', 'Features', 'Corpus', 'Findings', 'All runs', 'Last new feature');

        foreach ($engines as $property => $engine) {
            $statistics = $engine->statistics();
            $history    = $histories[$property] ?? new TargetHistory($property);

            printf(
                $format,
                $property,
                number_format($statistics->executions),
                number_format($statistics->features),
                number_format($statistics->corpusEntries),
                number_format($statistics->findings),
                number_format($history->executions()),
                SuiteStatus::lastNewFeature($history, $now),
            );
        }

        print PHP_EOL;
    }

    /**
     * Writes the status of the properties that were fuzzed with the cache
     * directory to status.json in it.
     */
    private function writeSuiteStatus(string $cacheDirectory): void
    {
        $file = $cacheDirectory . '/status.json';

        try {
            $json = SuiteStatus::fromCacheDirectory($cacheDirectory)->asJson(microtime(true));
        } catch (JsonException) {
            // @codeCoverageIgnoreStart
            $json = null;
            // @codeCoverageIgnoreEnd
        }

        if ($json === null || @file_put_contents($file, $json) === false) {
            printf('Warning: Cannot write "%s"' . PHP_EOL, $file);
        }
    }

    /**
     * The statistics of all properties together.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     */
    private function totals(array $engines, Dictionary $dictionary, float $start): Statistics
    {
        $totals = new Statistics($start);

        foreach ($engines as $engine) {
            $statistics = $engine->statistics();

            $totals->executions        += $statistics->executions;
            $totals->rejected          += $statistics->rejected;
            $totals->findings          += $statistics->findings;
            $totals->duplicateFindings += $statistics->duplicateFindings;
            $totals->features          += $statistics->features;
            $totals->corpusEntries     += $statistics->corpusEntries;
            $totals->reducedEntries    += $statistics->reducedEntries;
            $totals->removedInputs     += $statistics->removedInputs;
        }

        $totals->tokens = $dictionary->numberOfTokens();

        return $totals;
    }

    /**
     * The number of properties that are still fuzzed, for the status line.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     */
    private function activeProperties(array $engines): string
    {
        $active = 0;

        foreach ($engines as $engine) {
            if (!$engine->isDone()) {
                $active++;
            }
        }

        return sprintf('properties: %d of %d, ', $active, count($engines));
    }

    /**
     * Shows a finding as soon as it was minimized and stored, with the
     * property whose finding it is, when there is one.
     *
     * @param ?non-empty-string $property
     *
     * @return Closure(Minimization, string): void
     */
    private function findingListener(StatusLine $status, ?string $property): Closure
    {
        return static function (Minimization $minimization, string $directory) use ($status, $property): void
        {
            $notes = [];

            if ($minimization->isFlaky()) {
                $notes[] = 'flaky, not minimized';
            } elseif (!$minimization->minimalInputReproduced()) {
                $notes[] = 'the minimal input did not fail again, kept the input as found';
            }

            $status->finding(
                sprintf(
                    'Finding: %s%s' . PHP_EOL . '%s         %s',
                    $minimization->failure()->describe(),
                    $notes === [] ? '' : ' (' . implode(', ', $notes) . ')',
                    $property === null ? '' : '         ' . $property . PHP_EOL,
                    $directory,
                ),
            );
        };
    }

    /**
     * Logs every input that is submitted, when the inputs are logged.
     *
     * @param null|false|resource $log
     *
     * @return ?Closure(Job): void
     */
    private function inputLogger(mixed $log): ?Closure
    {
        if (!is_resource($log)) {
            return null;
        }

        return static function (Job $job) use ($log): void
        {
            fwrite($log, $job->isReplay() ? 'replay ' . implode(',', $job->choices()) . "\n" : 'generate ' . $job->seed() . "\n");
        };
    }

    /**
     * Writes the tokens that were found in the comparisons of the code that
     * was instrumented to a dictionary file next to the corpus, where they
     * can be looked at and passed with --dictionary. The dictionary file of an
     * earlier run is removed when no tokens were found, so that it does not
     * outlive the comparisons it was found in. Returns the number of tokens
     * that were written, or null.
     */
    private function writeFoundTokens(string $directory, Dictionary $dictionary): ?int
    {
        $path   = $directory . '/dictionary.txt';
        $tokens = $dictionary->foundTokens();

        if ($tokens === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return null;
        }

        $contents = (new DictionaryWriter)->write('the constant operands of comparisons that rattle found in the code that it instrumented', $tokens);

        if ((!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) ||
            @file_put_contents($path, $contents) === false) {
            printf('Warning: Cannot write the tokens that were found in comparisons to "%s"' . PHP_EOL, $path);

            return null;
        }

        return count($tokens);
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

    private function printHeader(TargetDescription $target, int $seed, int $jobs, WorkerConfiguration $configuration, string $corpusDirectory, int $numberOfStoredInputs, string $findingsDirectory, int $numberOfKnownFindings, int $maximumNumberOfChoices): void
    {
        printf(
            'Target:   %s (%s)' . PHP_EOL .
            'Seed:     %d' . PHP_EOL .
            'Jobs:     %d' . PHP_EOL .
            'Sources:  %s' . PHP_EOL .
            'Corpus:   %s (%d stored input%s)' . PHP_EOL .
            'Findings: %s (%d known)' . PHP_EOL,
            $target->name(),
            $target->isBytesTarget() ? sprintf('bytes, at most %d', $target->maximumLength()) : 'structured',
            $seed,
            $jobs,
            $configuration->sources() === [] ? 'none, no code is instrumented' : implode(', ', $configuration->sources()),
            $corpusDirectory,
            $numberOfStoredInputs,
            $numberOfStoredInputs === 1 ? '' : 's',
            $findingsDirectory,
            $numberOfKnownFindings,
        );

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
