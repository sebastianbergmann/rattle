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
use function glob;
use function printf;
use function sort;
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
        $setup    = new Setup;
        $executor = null;

        try {
            $executor = new ProcessExecutor($setup->workerConfiguration($arguments), $arguments->jobs() ?? 1, $setup->stderr($arguments));
            $target   = $executor->start();
        } catch (ConfigurationException|WorkerException $e) {
            $executor?->shutdown();

            print $e->getMessage() . PHP_EOL;

            return 2;
        }

        $paths = $this->filesIn($arguments->cacheDirectory() . '/' . $target->name() . '/corpus/*');

        foreach ((new FindingStore($arguments->findingsDirectory() . '/' . $target->name()))->inputs() as $path) {
            $paths[] = $path;
        }

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

                        $failures++;
                    }
                }
            }
        } catch (ProtocolException|WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            $executor->shutdown();
        }

        printf(
            '%sReplayed %d input%s, %d failed.' . PHP_EOL,
            $failures > 0 || $invalid > 0 ? PHP_EOL : '',
            count($jobs),
            count($jobs) === 1 ? '' : 's',
            $failures,
        );

        return $failures > 0 || $invalid > 0 ? 1 : 0;
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
