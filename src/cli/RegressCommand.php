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
 * of a target once. An input that makes the target fail is reported, so that
 * CI can check that findings that were fixed stay fixed.
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

        $phpunit = $configuration->phpunit();
        $report  = $arguments->sarifFile() === null ? null : new SarifReport((string) getcwd());

        if ($phpunit === null) {
            $outcome = $this->regress($arguments, $configuration, null, $report);

            if ($outcome === null) {
                return 2;
            }

            return $this->finish($outcome, null, $setup, $report, $arguments);
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
            $outcome = $this->regress($arguments, $configuration->withTarget($property), $property, $report);

            if ($outcome === null) {
                return 2;
            }

            $total['replayed'] += $outcome['replayed'];
            $total['failed']   += $outcome['failed'];
            $total['invalid']  += $outcome['invalid'];
        }

        return $this->finish($total, count($properties), $setup, $report, $arguments);
    }

    /**
     * Shows the outcome, and writes the inputs that failed in SARIF when
     * --sarif is given.
     *
     * @param array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} $outcome
     * @param ?positive-int                                                                          $numberOfProperties null for a fuzz target
     *
     * @return 0|1|2
     */
    private function finish(array $outcome, ?int $numberOfProperties, Setup $setup, ?SarifReport $report, Arguments $arguments): int
    {
        $exit = $this->summarize($outcome, $numberOfProperties);

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
     * @param ?non-empty-string $property the test ID of the property, null for a fuzz target, whose worker reports the name of its directories
     *
     * @return ?array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} null when the inputs could not be replayed
     */
    private function regress(Arguments $arguments, WorkerConfiguration $configuration, ?string $property, ?SarifReport $report): ?array
    {
        $directory = $property === null ? null : PropertyDirectory::nameOf($property);

        if ($directory !== null) {
            $paths = $this->inputsIn($arguments, $directory);

            if ($paths === []) {
                return ['replayed' => 0, 'failed' => 0, 'invalid' => 0];
            }
        }

        $executor = new ProcessExecutor($configuration, $arguments->jobs() ?? 1, (new Setup)->stderr($arguments));

        try {
            $target = $executor->start();
        } catch (WorkerException $e) {
            $executor->shutdown();

            print $e->getMessage() . PHP_EOL;

            return null;
        }

        $paths ??= $this->inputsIn($arguments, $target->name());

        $jobs     = [];
        $files    = [];
        $invalid  = 0;
        $failures = 0;

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

        try {
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

                    if ($result->isFinding()) {
                        printf('Fails: %s' . PHP_EOL . '       %s' . PHP_EOL, $files[$result->id()] ?? '', $result->failure()->describe());

                        $report?->add($result->failure(), $property);

                        $failures++;
                    }
                }
            }
        } catch (ProtocolException|WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return null;
        } finally {
            $executor->shutdown();
        }

        return ['replayed' => count($jobs), 'failed' => $failures, 'invalid' => $invalid];
    }

    /**
     * @param array{replayed: non-negative-int, failed: non-negative-int, invalid: non-negative-int} $outcome
     * @param ?positive-int                                                                          $numberOfProperties null for a fuzz target
     *
     * @return 0|1
     */
    private function summarize(array $outcome, ?int $numberOfProperties): int
    {
        printf(
            '%sReplayed %d input%s%s, %d failed.' . PHP_EOL,
            $outcome['failed'] > 0 || $outcome['invalid'] > 0 ? PHP_EOL : '',
            $outcome['replayed'],
            $outcome['replayed'] === 1 ? '' : 's',
            $numberOfProperties === null ? '' : sprintf(' of %d propert%s', $numberOfProperties, $numberOfProperties === 1 ? 'y' : 'ies'),
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
