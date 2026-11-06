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
use function spl_object_id;
use function sprintf;
use function stream_isatty;
use function strlen;
use function unlink;
use Closure;
use JsonException;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

/**
 * Fuzzes a target, several targets together, or the properties of a PHPUnit
 * test suite together, until a limit is reached or Ctrl+C is pressed.
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
            $phpBinaries   = $setup->phpBinaries($arguments);
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

        if ($phpunit === null && $configuration->targets() === []) {
            return $this->fuzz($arguments, $configuration, $this->executionOptions($arguments) . $configuration->target(), $arguments->maximumTime(), $stderr, $jobs, $seed, $dictionary, $isInterrupted, $phpBinaries);
        }

        if ($phpunit === null) {
            return $this->fuzzTargets($arguments, $configuration, $stderr, $jobs, $seed, $dictionary, $isInterrupted, $phpBinaries);
        }

        return $this->fuzzTestSuite($arguments, $configuration, $phpunit, $stderr, $jobs, $seed, $dictionary, $isInterrupted, $phpBinaries);
    }

    /**
     * @param string                      $reproduction  what is passed to "rattle run" to execute an input of the target again
     * @param ?float                      $maximumTime   in seconds, null for no time limit
     * @param ?Closure(int, string): void $stderr
     * @param positive-int                $jobs
     * @param list<non-empty-string>      $tokens        the tokens of the dictionary file
     * @param Closure(): bool             $isInterrupted
     * @param list<PhpBinary>             $phpBinaries   the versions of PHP that the corpus and the findings are compared on
     *
     * @return 0|1|2
     */
    private function fuzz(Arguments $arguments, WorkerConfiguration $configuration, string $reproduction, ?float $maximumTime, ?Closure $stderr, int $jobs, int $seed, array $tokens, Closure $isInterrupted, array $phpBinaries): int
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

        $coverage = $this->openCoverageLog($arguments);

        if ($coverage === false) {
            $executor->shutdown();

            printf('Cannot write the coverage log "%s"' . PHP_EOL, (string) $arguments->coverageLog());

            return 2;
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

        $report       = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());
        $differential = null;
        $differences  = null;

        if ($phpBinaries !== []) {
            $differential = $this->differentialExecutor($minimizationExecutor, $phpBinaries, $configuration, $stderr, null);
            $handler      = new FindingProcessor(
                new Minimizer($differential, $arguments->maximumNumberOfShrinkingAttempts(), $isInterrupted),
                $store,
                $target,
                $this->phpOptions($phpBinaries) . $reproduction,
                $seed,
                $this->findingListener($status, null),
            );

            $differences = new DifferenceFinder($differential, $report === null ? $handler : new FindingRecorder($handler, $report, null));
        }

        $this->printHeader($target, $seed, $jobs, $configuration, $corpusDirectory, $numberOfStored, $findingsDirectory, $findings->numberOfKnownFindings(), $arguments->maximumNumberOfChoices(), $differential);

        foreach ($loader->warnings() as $warning) {
            print 'Warning: ' . $warning . PHP_EOL;
        }

        $log = null;

        if ($arguments->inputLog() !== null) {
            $log = fopen($arguments->inputLog(), 'w');
        }

        $executionLog = $this->executionLog($coverage, $name, $seed, $jobs);

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
            null,
            $differences === null ? null : $differences->check(...),
            $executionLog,
        );

        // the scheduler keeps what the run learned about the target for later
        // runs, as for targets that are fuzzed together, and does not change
        // the inputs that the engine composes
        $engine->start($initialInputs);

        $scheduler = new TargetScheduler($executor, [$name => $engine], $seed, null, $isInterrupted, null, [$name => TargetHistory::fromFile($this->historyFile($arguments, $name), $name)]);

        try {
            $scheduler->run();
        } catch (CorpusException|FindingException|ProtocolException|WorkerException $e) {
            print PHP_EOL . $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            $executor->shutdown();
            $minimizationExecutor->shutdown();
            $differential?->shutdown();

            if (is_resource($log)) {
                fclose($log);
            }

            $this->closeCoverageLog($coverage, $executionLog, $engine->statistics());
        }

        $statistics = $engine->statistics();

        $status->finish($statistics);

        $this->writeHistory($arguments, $name, $scheduler->histories()[$name] ?? null);
        $this->writeSuiteStatus($arguments->cacheDirectory());

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
     * Fuzzes the properties of a PHPUnit test suite together (see
     * fuzzTogether()). The dictionary is shared by all properties, because
     * the constant operands of comparisons are found in the code that all
     * properties exercise.
     *
     * @param ?Closure(int, string): void $stderr
     * @param positive-int                $jobs
     * @param list<non-empty-string>      $tokens        the tokens of the dictionary file
     * @param Closure(): bool             $isInterrupted
     * @param list<PhpBinary>             $phpBinaries   the versions of PHP that the corpus and the findings are compared on
     *
     * @return 0|1|2
     */
    private function fuzzTestSuite(Arguments $arguments, WorkerConfiguration $configuration, PhpunitTestSuite $phpunit, ?Closure $stderr, int $jobs, int $seed, array $tokens, Closure $isInterrupted, array $phpBinaries): int
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
        } elseif (count($properties) > 1 && ($arguments->corpusDirectories() !== [] || $arguments->inputLog() !== null || $arguments->coverageLog() !== null)) {
            // the inputs of a corpus directory and the logs of the inputs
            // belong to one target
            $error = 'The options --corpus, --log-inputs, and --log-coverage can only be used with --phpunit when the filter selects one property';
        }

        foreach ($arguments->corpusDirectories() as $directory) {
            if ($error === null && !is_dir($directory)) {
                $error = sprintf('The corpus directory "%s" does not exist', $directory);
            }
        }

        if ($error !== null || $properties === []) {
            $executor->shutdown();

            print $error . PHP_EOL;

            return 2;
        }

        $dictionary = new Dictionary($tokens, Sentinels::tokens());
        $targets    = [];

        foreach ($properties as $property) {
            $targets[] = new FuzzedTarget(
                $property,
                PropertyDirectory::nameOf($property),
                new TargetDescription($property, null, $target->maximumNumberOfChoices()),
                $this->executionOptions($arguments) . $this->reproduction($phpunit, $property),
                $configuration->withTarget($property),
                $dictionary,
            );
        }

        return $this->fuzzTogether(
            $arguments,
            $configuration->withTarget(''),
            $executor,
            $targets,
            true,
            sprintf('PHPUnit test suite (%d propert%s)', count($properties), count($properties) === 1 ? 'y' : 'ies'),
            $stderr,
            $jobs,
            $seed,
            $isInterrupted,
            $phpBinaries,
        );
    }

    /**
     * Fuzzes the targets of several target files together (see
     * fuzzTogether()). Every target has a dictionary of its own, because the
     * targets of a project exercise different code: the constant operands of
     * the comparisons in the code that is instrumented while an input of a
     * target is executed are added to the dictionary of that target, and the
     * ones in the code that is instrumented while a worker starts to the
     * dictionaries of all targets (see Worker).
     *
     * @param ?Closure(int, string): void $stderr
     * @param positive-int                $jobs
     * @param list<non-empty-string>      $tokens        the tokens of the dictionary file
     * @param Closure(): bool             $isInterrupted
     * @param list<PhpBinary>             $phpBinaries   the versions of PHP that the corpus and the findings are compared on
     *
     * @return 0|1|2
     */
    private function fuzzTargets(Arguments $arguments, WorkerConfiguration $configuration, ?Closure $stderr, int $jobs, int $seed, array $tokens, Closure $isInterrupted, array $phpBinaries): int
    {
        // the inputs of a corpus directory, raw seed files, and the logs of
        // the inputs belong to one target
        if ($arguments->corpusDirectories() !== [] || $arguments->seedsDirectory() !== null || $arguments->inputLog() !== null || $arguments->coverageLog() !== null) {
            print 'The options --corpus, --seeds, --log-inputs, and --log-coverage can only be used when one target is fuzzed' . PHP_EOL;

            return 2;
        }

        $executor = new ProcessExecutor($configuration, $jobs, $stderr);

        try {
            $descriptions = $executor->targets() ?? [];
            $files        = $executor->targetFiles();
        } catch (WorkerException $e) {
            $executor->shutdown();

            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $targets = [];

        foreach ($descriptions as $name => $description) {
            $file = $files[$name] ?? $name;

            $targets[] = new FuzzedTarget(
                $name,
                $name,
                $description,
                $this->executionOptions($arguments) . $file,
                $configuration->withTarget($file),
                new Dictionary($tokens, Sentinels::tokens()),
            );
        }

        if ($targets === []) {
            // @codeCoverageIgnoreStart
            $executor->shutdown();

            return 2;
            // @codeCoverageIgnoreEnd
        }

        return $this->fuzzTogether(
            $arguments,
            $configuration,
            $executor,
            $targets,
            false,
            sprintf('%d fuzz targets (%s)', count($targets), implode(', ', array_keys($descriptions))),
            $stderr,
            $jobs,
            $seed,
            $isInterrupted,
            $phpBinaries,
        );
    }

    /**
     * Fuzzes several targets together, with one pool of workers that serves
     * all of them (see TargetScheduler). Every target has an engine, a
     * corpus, findings, and a history of its own.
     *
     * @param WorkerConfiguration          $configuration the configuration of the workers that serve all targets
     * @param ProcessExecutor              $executor      the executor whose workers serve all targets, which were started
     * @param non-empty-list<FuzzedTarget> $targets
     * @param bool                         $properties    whether the targets are the properties of a PHPUnit test suite
     * @param non-empty-string             $description   what is fuzzed, for the header
     * @param ?Closure(int, string): void  $stderr
     * @param positive-int                 $jobs
     * @param Closure(): bool              $isInterrupted
     * @param list<PhpBinary>              $phpBinaries   the versions of PHP that the corpus and the findings are compared on
     *
     * @return 0|1|2
     */
    private function fuzzTogether(Arguments $arguments, WorkerConfiguration $configuration, ProcessExecutor $executor, array $targets, bool $properties, string $description, ?Closure $stderr, int $jobs, int $seed, Closure $isInterrupted, array $phpBinaries): int
    {
        // the log of the coverage belongs to one target, the only one
        $coverage = $this->openCoverageLog($arguments);

        if ($coverage === false) {
            $executor->shutdown();

            printf('Cannot write the coverage log "%s"' . PHP_EOL, (string) $arguments->coverageLog());

            return 2;
        }

        $executionLog          = $this->executionLog($coverage, $targets[0]->name(), $seed, $jobs);
        $status                = new StatusLine(STDOUT, stream_isatty(STDOUT), static fn (): float => microtime(true));
        $report                = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());
        $ids                   = new JobIds;
        $loader                = new InputLoader;
        $log                   = $arguments->inputLog() !== null ? fopen($arguments->inputLog(), 'w') : null;
        $engines               = [];
        $histories             = [];
        $minimizationExecutors = [];
        $numberOfStoredInputs  = 0;
        $numberOfKnownFindings = 0;

        // the inputs of all targets are compared on the versions of PHP with
        // executors whose workers serve all targets, which are only started
        // when they are needed
        $comparison = $phpBinaries === [] ? null : $this->differentialExecutor(new ProcessExecutor($configuration, 1, $stderr), $phpBinaries, $configuration, $stderr, null);

        foreach ($targets as $index => $target) {
            $name            = $target->name();
            $corpusDirectory = $arguments->cacheDirectory() . '/' . $target->directory() . '/corpus';
            $initialInputs   = $loader->choiceSequencesIn($corpusDirectory);

            $numberOfStoredInputs += count($initialInputs);

            foreach ($arguments->corpusDirectories() as $directory) {
                foreach ($loader->choiceSequencesIn($directory) as $input) {
                    $initialInputs[] = $input;
                }
            }

            // the worker that minimizes the inputs of a target is only started
            // when the target has a finding
            $minimizationExecutors[] = $minimizationExecutor = new ProcessExecutor($target->configuration(), 1, $stderr);

            $findings = new FindingProcessor(
                new Minimizer($minimizationExecutor, $arguments->maximumNumberOfShrinkingAttempts(), $isInterrupted),
                new FindingStore($arguments->findingsDirectory() . '/' . $target->directory()),
                $target->description(),
                $target->reproduction(),
                $seed,
                $this->findingListener($status, $name),
            );

            $numberOfKnownFindings += $findings->numberOfKnownFindings();

            $differences = null;

            if ($comparison !== null) {
                $differential = $comparison->forTarget($name);
                $handler      = new FindingProcessor(
                    new Minimizer($differential, $arguments->maximumNumberOfShrinkingAttempts(), $isInterrupted),
                    new FindingStore($arguments->findingsDirectory() . '/' . $target->directory()),
                    $target->description(),
                    $this->phpOptions($phpBinaries) . $target->reproduction(),
                    $seed,
                    $this->findingListener($status, $name),
                );

                $differences = new DifferenceFinder($differential, $report === null ? $handler : $this->findingRecorder($handler, $report, $name, $properties));
            }

            $corpus = new Corpus($corpusDirectory);

            $engines[$name] = new Engine(
                $executor,
                $corpus,
                $target->dictionary(),
                Mutator::create($corpus, $target->dictionary(), $target->description()->maximumNumberOfChoices()),
                $report === null ? $findings : $this->findingRecorder($findings, $report, $name, $properties),
                // every target draws from a random number generator of its
                // own, so that the targets are not mutated in lockstep
                new EngineConfiguration(Engine::seedOf($seed, $index), $arguments->maximumNumberOfExecutions(), null, $arguments->stopOnFinding(), EngineConfiguration::DEFAULT_BATCH_SIZE, $name),
                null,
                $this->inputLogger($log),
                $isInterrupted,
                $ids,
                $differences === null ? null : $differences->check(...),
                $executionLog,
            );

            $engines[$name]->start($initialInputs);

            $histories[$name] = TargetHistory::fromFile($this->historyFile($arguments, $target->directory()), $name);
        }

        printf(
            'Target:   %s' . PHP_EOL .
            'Seed:     %d' . PHP_EOL .
            'Jobs:     %d' . PHP_EOL .
            'Sources:  %s' . PHP_EOL .
            'Corpus:   %s (%d stored input%s)' . PHP_EOL .
            'Findings: %s (%d known)' . PHP_EOL,
            $description,
            $seed,
            $jobs,
            $configuration->sources() === [] ? 'none, no code is instrumented' : implode(', ', $configuration->sources()),
            $arguments->cacheDirectory(),
            $numberOfStoredInputs,
            $numberOfStoredInputs === 1 ? '' : 's',
            $arguments->findingsDirectory(),
            $numberOfKnownFindings,
        );

        if ($comparison !== null) {
            printf('Versions: %s, which the corpus and the findings are compared on' . PHP_EOL, implode(', ', $comparison->labels()));
        }

        foreach ($targets as $target) {
            if ($target->description()->maximumNumberOfChoices() > $arguments->maximumNumberOfChoices()) {
                printf(
                    'The maximum number of choices was raised from %d to %d for %s, which it needs.' . PHP_EOL,
                    $arguments->maximumNumberOfChoices(),
                    $target->description()->maximumNumberOfChoices(),
                    $target->name(),
                );
            }
        }

        print PHP_EOL;

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
            function () use ($status, $engines, $targets, $start, $properties): void
            {
                $status->update($this->totals($engines, $targets, $start), $this->activeTargets($engines, $properties));
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

            $comparison?->shutdown();

            if (is_resource($log)) {
                fclose($log);
            }

            $first = $engines[$targets[0]->name()] ?? null;

            if ($first !== null) {
                $this->closeCoverageLog($coverage, $executionLog, $first->statistics());
            }
        }

        $totals = $this->totals($engines, $targets, $start);

        $status->finish($totals, $this->activeTargets($engines, $properties));

        // what the run learned about the targets is kept for later runs
        foreach ($targets as $target) {
            $this->writeHistory($arguments, $target->directory(), $scheduler->histories()[$target->name()] ?? null);
        }

        $this->writeSuiteStatus($arguments->cacheDirectory());

        print PHP_EOL;

        $this->printTable($engines, $scheduler->histories(), $properties ? 'Property' : 'Target');
        $this->printSummary($totals, $isInterrupted());

        $written = null;

        foreach ($targets as $target) {
            $written = $this->writeFoundTokens($arguments->cacheDirectory() . '/' . $target->directory(), $target->dictionary());

            if ($written !== null && !$properties) {
                printf(
                    '%d token%s found in comparisons %s written to %s' . PHP_EOL,
                    $written,
                    $written === 1 ? ' that was' : 's that were',
                    $written === 1 ? 'was' : 'were',
                    $arguments->cacheDirectory() . '/' . $target->directory() . '/dictionary.txt',
                );
            }
        }

        // the properties share the dictionary
        if ($written !== null && $properties) {
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
     * An executor that executes inputs on the version of PHP that fuzzes,
     * with the given executor, and on the versions of the PHP binaries, with
     * an executor of one worker each.
     *
     * @param non-empty-list<PhpBinary>   $phpBinaries
     * @param ?Closure(int, string): void $stderr
     * @param ?non-empty-string           $property    the property that every input is executed for
     */
    private function differentialExecutor(ProcessExecutor $first, array $phpBinaries, WorkerConfiguration $configuration, ?Closure $stderr, ?string $property): DifferentialExecutor
    {
        $labels    = DifferentialExecutor::labelsOf([$first->php(), ...$phpBinaries]);
        $executors = [$labels[0] => $first];

        foreach ($phpBinaries as $index => $php) {
            $executors[$labels[$index + 1] ?? $php->path()] = new ProcessExecutor($configuration, 1, $stderr, $php);
        }

        return new DifferentialExecutor($executors, $property);
    }

    /**
     * The options of "rattle run" that run an input on the versions of PHP
     * of the PHP binaries as well.
     *
     * @param list<PhpBinary> $phpBinaries
     */
    private function phpOptions(array $phpBinaries): string
    {
        $options = '';

        foreach ($phpBinaries as $php) {
            $options .= '--php ' . escapeshellarg($php->path()) . ' ';
        }

        return $options;
    }

    /**
     * Records the findings of a target in SARIF, with the property or the
     * fuzz target whose findings they are.
     *
     * @param non-empty-string $name       the test ID of the property or the name of the fuzz target
     * @param bool             $properties whether the target is a property of a PHPUnit test suite
     */
    private function findingRecorder(FindingHandler $handler, SarifReport $report, string $name, bool $properties): FindingRecorder
    {
        return $properties ? new FindingRecorder($handler, $report, $name) : new FindingRecorder($handler, $report, null, $name);
    }

    /**
     * The file in which what rattle knows about a target from the runs that
     * fuzzed it is kept, next to its corpus.
     *
     * @param non-empty-string $directory the name of the directories of the target
     */
    private function historyFile(Arguments $arguments, string $directory): string
    {
        return $arguments->cacheDirectory() . '/' . $directory . '/statistics.json';
    }

    /**
     * Keeps what a run learned about a target for later runs.
     *
     * @param non-empty-string $directory the name of the directories of the target
     */
    private function writeHistory(Arguments $arguments, string $directory, ?TargetHistory $history): void
    {
        if ($history === null) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $file = $this->historyFile($arguments, $directory);

        if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o777, true)) || !$history->write($file)) {
            printf('Warning: Cannot write "%s"' . PHP_EOL, $file);
        }
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
     * Shows how fuzzing went for every target in the run, how often it was
     * executed in all runs, and how long ago it last discovered features.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     * @param array<non-empty-string, TargetHistory>    $histories
     * @param non-empty-string                          $heading   of the column of the targets
     */
    private function printTable(array $engines, array $histories, string $heading): void
    {
        $width = strlen($heading);
        $now   = microtime(true);

        foreach (array_keys($engines) as $property) {
            $width = max($width, strlen($property));
        }

        $format = '%-' . $width . 's  %10s  %8s  %6s  %8s  %10s  %s' . PHP_EOL;

        printf($format, $heading, 'Executions', 'Features', 'Corpus', 'Findings', 'All runs', 'Last new feature');

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
     * Writes the status of the targets that were fuzzed with the cache
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
     * The statistics of all targets together, with the tokens of all their
     * dictionaries.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     * @param non-empty-list<FuzzedTarget>              $targets
     */
    private function totals(array $engines, array $targets, float $start): Statistics
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
            $totals->instrumentedFiles += $statistics->instrumentedFiles;
        }

        $dictionaries = [];

        foreach ($targets as $target) {
            $dictionaries[spl_object_id($target->dictionary())] = $target->dictionary();
        }

        foreach ($dictionaries as $dictionary) {
            $totals->tokens += $dictionary->numberOfTokens();
        }

        return $totals;
    }

    /**
     * The number of targets that are still fuzzed, for the status line.
     *
     * @param non-empty-array<non-empty-string, Engine> $engines
     * @param bool                                      $properties whether the targets are the properties of a PHPUnit test suite
     */
    private function activeTargets(array $engines, bool $properties): string
    {
        $active = 0;

        foreach ($engines as $engine) {
            if (!$engine->isDone()) {
                $active++;
            }
        }

        return sprintf('%s: %d of %d, ', $properties ? 'properties' : 'targets', $active, count($engines));
    }

    /**
     * Shows a finding as soon as it was minimized and stored, with the
     * target whose finding it is when several targets are fuzzed.
     *
     * @param ?non-empty-string $property the test ID of the property or the name of the target
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
     * Opens the file of the coverage log, when it is asked for, and returns
     * false when the file cannot be written.
     *
     * @return null|false|resource
     */
    private function openCoverageLog(Arguments $arguments): mixed
    {
        if ($arguments->coverageLog() === null) {
            return null;
        }

        return @fopen($arguments->coverageLog(), 'w');
    }

    /**
     * The execution log that is written to the file of the coverage log,
     * which has started, when the coverage is logged.
     *
     * @param null|resource    $coverage
     * @param non-empty-string $target
     * @param positive-int     $jobs
     */
    private function executionLog(mixed $coverage, string $target, int $seed, int $jobs): ?JsonLinesExecutionLog
    {
        if (!is_resource($coverage)) {
            return null;
        }

        $log = new JsonLinesExecutionLog($coverage);

        $log->start($target, $seed, $jobs);

        return $log;
    }

    /**
     * @param null|resource $coverage
     */
    private function closeCoverageLog(mixed $coverage, ?JsonLinesExecutionLog $log, Statistics $statistics): void
    {
        if (!is_resource($coverage)) {
            return;
        }

        $log?->end($statistics);

        fclose($coverage);
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

        if ($arguments->opcache()) {
            $options .= '--opcache ';
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

    private function printHeader(TargetDescription $target, int $seed, int $jobs, WorkerConfiguration $configuration, string $corpusDirectory, int $numberOfStoredInputs, string $findingsDirectory, int $numberOfKnownFindings, int $maximumNumberOfChoices, ?DifferentialExecutor $differential): void
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

        if ($differential !== null) {
            printf('Versions: %s, which the corpus and the findings are compared on' . PHP_EOL, implode(', ', $differential->labels()));
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
