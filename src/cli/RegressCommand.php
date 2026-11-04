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
use function array_slice;
use function count;
use function file_get_contents;
use function getcwd;
use function glob;
use function printf;
use function sort;
use function sprintf;
use SebastianBergmann\ChoiceSequence\Decoder;
use SebastianBergmann\ChoiceSequence\InvalidChoiceSequenceException;

/**
 * Replays every input in the corpus directory and in the findings directory
 * of a target once, or of several targets one after the other. An input that
 * makes its target fail is reported, so that CI can check that findings that
 * were fixed stay fixed.
 *
 * With --php, the inputs are replayed on other versions of PHP as well, and an
 * input whose outcome differs between the versions is reported, too.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class RegressCommand implements Command
{
    private const int BATCH_SIZE = 16;

    public function run(Arguments $arguments): int
    {
        $setup = new Setup;

        try {
            $configuration = $setup->workerConfiguration($arguments);
        } catch (ConfigurationException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        try {
            $phpBinaries = $setup->phpBinaries($arguments);
        } catch (ConfigurationException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $phpunit = $configuration->phpunit();
        $report  = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());

        if ($phpunit === null && $configuration->targets() === []) {
            $outcome = $this->regress($arguments, $configuration, null, $report, $phpBinaries);

            if ($outcome === null) {
                return 2;
            }

            return $this->finish($outcome, '', $setup, $report, $arguments);
        }

        if ($phpunit === null) {
            $total       = ['replayed' => 0, 'failed' => 0, 'invalid' => 0];
            $targetFiles = [];

            foreach ($configuration->targets() as $file) {
                $outcome = $this->regress($arguments, $configuration->withTarget($file), null, $report, $phpBinaries, $targetFiles);

                if ($outcome === null) {
                    return 2;
                }

                $total['replayed'] += $outcome['replayed'];
                $total['failed']   += $outcome['failed'];
                $total['invalid']  += $outcome['invalid'];
            }

            return $this->finish($total, sprintf(' of %d targets', count($configuration->targets())), $setup, $report, $arguments);
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

        $total = ['replayed' => 0, 'failed' => 0, 'invalid' => 0];

        foreach ($properties as $property) {
            $outcome = $this->regress($arguments, $configuration->withTarget($property), $property, $report, $phpBinaries);

            if ($outcome === null) {
                return 2;
            }

            $total['replayed'] += $outcome['replayed'];
            $total['failed']   += $outcome['failed'];
            $total['invalid']  += $outcome['invalid'];
        }

        return $this->finish($total, sprintf(' of %d propert%s', count($properties), count($properties) === 1 ? 'y' : 'ies'), $setup, $report, $arguments);
    }

    /**
     * Shows the outcome, and writes the inputs that failed in SARIF when
     * --sarif is given.
     *
     * @param array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} $outcome
     * @param string                                                                                 $of      what the inputs belong to, " of 2 properties", for instance, and empty for one fuzz target
     *
     * @return 0|1|2
     */
    private function finish(array $outcome, string $of, Setup $setup, ?SarifReport $report, Arguments $arguments): int
    {
        $exit = $this->summarize($outcome, $of);

        if (!$setup->writeSarifReport($report, $arguments->sarifFile())) {
            return 2;
        }

        return $exit;
    }

    /**
     * Replays the inputs in the corpus and findings directories of a target.
     * The directories of a fuzz target are named after the name that its
     * worker reports, and the ones of a property after its test ID; workers
     * are not started for a property without inputs.
     *
     * With the binaries of --php, the inputs are replayed on their versions of
     * PHP as well, and an input fails when it fails on one of the versions, or
     * when its outcome differs between them (see DifferentialExecutor).
     *
     * When several fuzz targets are replayed, a failure in SARIF names its
     * target, and two target files whose targets have the same name, and so
     * the same directories, are an error.
     *
     * @param ?non-empty-string         $property    the test ID of the property, null for a fuzz target, whose worker reports the name of its directories
     * @param list<PhpBinary>           $phpBinaries
     * @param ?array<array-key, string> $targetFiles the target files of the fuzz targets that were replayed before, by the names of their targets, when several fuzz targets are replayed
     *
     * @return ?array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} null when the inputs could not be replayed
     */
    private function regress(Arguments $arguments, WorkerConfiguration $configuration, ?string $property, ?SarifReport $report, array $phpBinaries, ?array &$targetFiles = null): ?array
    {
        $directory = $property === null ? null : PropertyDirectory::nameOf($property);

        if ($directory !== null) {
            $paths = $this->inputsIn($arguments, $directory);

            if ($paths === []) {
                return ['replayed' => 0, 'failed' => 0, 'invalid' => 0];
            }
        }

        $stderr   = (new Setup)->stderr($arguments);
        $executor = new ProcessExecutor($configuration, $arguments->jobs() ?? 1, $stderr);

        try {
            $target = $executor->start();
        } catch (WorkerException $e) {
            $executor->shutdown();

            print $e->getMessage() . PHP_EOL;

            return null;
        }

        $name = null;

        if ($targetFiles !== null) {
            $name = $target->name();

            if (isset($targetFiles[$name])) {
                $executor->shutdown();

                printf('The target files "%s" and "%s" both return a target named "%s"' . PHP_EOL, $targetFiles[$name], $configuration->target(), $name);

                return null;
            }

            $targetFiles[$name] = $configuration->target();
        }

        $paths ??= $this->inputsIn($arguments, $target->name());

        $jobs    = [];
        $files   = [];
        $invalid = 0;

        foreach ($paths as $path) {
            try {
                $jobs[] = Job::replay(count($jobs), (new Decoder)->decode((string) file_get_contents($path))->choices());
            } catch (InvalidChoiceSequenceException $e) {
                printf('Invalid: %s: %s' . PHP_EOL, $path, $e->getMessage());

                $invalid++;

                continue;
            }

            $files[count($jobs) - 1] = $path;
        }

        $labels  = DifferentialExecutor::labelsOf([$executor->php(), ...$phpBinaries]);
        $results = [];

        try {
            $results[$labels[0]] = $this->replay($executor, $jobs);

            foreach ($phpBinaries as $index => $php) {
                $other = new ProcessExecutor($configuration, $arguments->jobs() ?? 1, $stderr, $php);

                try {
                    $other->start();

                    $results[$labels[$index + 1] ?? $php->path()] = $this->replay($other, $jobs);
                } finally {
                    $other->shutdown();
                }
            }
        } catch (ProtocolException|WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return null;
        } finally {
            $executor->shutdown();
        }

        $failures = 0;

        foreach ($jobs as $job) {
            $failed   = false;
            $versions = [];

            foreach ($results as $label => $resultsOfVersion) {
                $result = $resultsOfVersion[$job->id()] ?? null;

                if ($result === null) {
                    // @codeCoverageIgnoreStart
                    continue;
                    // @codeCoverageIgnoreEnd
                }

                $versions[$label] = $result;

                if ($result->isFinding()) {
                    printf(
                        'Fails%s: %s' . PHP_EOL . '       %s' . PHP_EOL,
                        $phpBinaries === [] ? '' : ' on ' . $label,
                        $files[$job->id()] ?? '',
                        $result->failure()->describe(),
                    );

                    $report?->add($result->failure(), $property, $name);

                    $failed = true;
                }
            }

            if ($phpBinaries !== [] && $versions !== []) {
                $difference = DifferentialExecutor::differenceOf($job->id(), $versions);

                if ($difference->isFinding()) {
                    printf('Differs: %s' . PHP_EOL . '       %s' . PHP_EOL, $files[$job->id()] ?? '', $difference->failure()->describe());

                    $report?->add($difference->failure(), $property, $name);

                    $failed = true;
                }
            }

            if ($failed) {
                $failures++;
            }
        }

        return ['replayed' => count($jobs), 'failed' => $failures, 'invalid' => $invalid];
    }

    /**
     * Executes the inputs with the executor, and returns their results by the
     * IDs of the inputs.
     *
     * @param list<Job> $jobs
     *
     * @throws ProtocolException
     * @throws WorkerException
     *
     * @return array<non-negative-int, Result>
     */
    private function replay(Executor $executor, array $jobs): array
    {
        $results     = [];
        $pending     = $jobs;
        $outstanding = 0;

        while ($pending !== [] || $outstanding > 0) {
            while ($pending !== [] && $executor->capacity() > 0) {
                $batch = array_slice($pending, 0, self::BATCH_SIZE);

                $executor->submit($batch);

                $outstanding += count($batch);
                $pending = array_slice($pending, self::BATCH_SIZE);
            }

            foreach ($executor->collect() as $result) {
                $outstanding--;

                $results[$result->id()] = $result;
            }
        }

        return $results;
    }

    /**
     * @param array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} $outcome
     * @param string                                                                                 $of      what the inputs belong to, " of 2 properties", for instance, and empty for one fuzz target
     *
     * @return 0|1
     */
    private function summarize(array $outcome, string $of): int
    {
        printf(
            '%sReplayed %d input%s%s, %d failed.' . PHP_EOL,
            $outcome['failed'] > 0 || $outcome['invalid'] > 0 ? PHP_EOL : '',
            $outcome['replayed'],
            $outcome['replayed'] === 1 ? '' : 's',
            $of,
            $outcome['failed'],
        );

        return $outcome['failed'] > 0 || $outcome['invalid'] > 0 ? 1 : 0;
    }

    /**
     * The inputs in the corpus directory and in the findings directory of a
     * target.
     *
     * @return list<string>
     */
    private function inputsIn(Arguments $arguments, string $directory): array
    {
        $paths = $this->filesIn($arguments->cacheDirectory() . '/' . $directory . '/corpus/*');

        foreach ((new FindingStore($arguments->findingsDirectory() . '/' . $directory))->inputs() as $path) {
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $pattern): array
    {
        $files = glob($pattern);

        if ($files === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        sort($files);

        return $files;
    }
}
