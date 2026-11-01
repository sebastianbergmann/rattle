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
use function file_get_contents;
use function is_file;
use function printf;
use function reset;
use function sprintf;
use function str_replace;
use SebastianBergmann\ChoiceSequence\Decoder;
use SebastianBergmann\ChoiceSequence\InvalidChoiceSequenceException;

/**
 * Runs one input once and shows what happened.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class RunCommand implements Command
{
    /**
     * @throws ConfigurationException
     *
     * @return list<non-negative-int>
     */
    public static function choicesIn(string $input, bool $raw, TargetDescription $target): array
    {
        if (!is_file($input)) {
            throw new ConfigurationException(sprintf('The input file "%s" does not exist', $input));
        }

        $content = (string) file_get_contents($input);

        if ($raw) {
            if (!$target->isBytesTarget()) {
                throw new ConfigurationException('A raw input can only be run for a bytes target');
            }

            return BytesEncoding::choicesFor($content, $target->maximumLength());
        }

        try {
            return (new Decoder)->decode($content)->choices();
        } catch (InvalidChoiceSequenceException $e) {
            throw new ConfigurationException(sprintf('The input file "%s" is not a valid choice sequence: %s', $input, $e->getMessage()));
        }
    }

    /**
     * @param bool $property whether the result is the result of an example of a PHPUnit property, whose values are the arguments, the drawn values, and the steps of the example as PHPUnit prints them
     */
    public static function printResult(Result $result, bool $property = false): void
    {
        printf('Outcome: %s' . PHP_EOL, $result->outcome()->value);

        $failure = $result->failure();

        if ($failure !== null) {
            printf(PHP_EOL . 'Failure: %s' . PHP_EOL, $failure->describe());

            if ($failure->message() !== '') {
                printf(PHP_EOL . 'Message: %s' . PHP_EOL, $failure->message());
            }

            if ($failure->trace() !== '') {
                printf(PHP_EOL . 'Stack trace:' . PHP_EOL . '  %s' . PHP_EOL, str_replace("\n", "\n  ", $failure->trace()));
            }
        }

        $drawnValues = $result->drawnValues() ?? [];

        if ($property && $drawnValues !== []) {
            print PHP_EOL . 'Values:' . PHP_EOL;

            foreach ($drawnValues as $line) {
                print ($line === '' ? '' : '  ' . str_replace("\n", "\n  ", $line)) . PHP_EOL;
            }

            return;
        }

        if ($drawnValues !== []) {
            print PHP_EOL . 'Drawn values:' . PHP_EOL;

            foreach ($drawnValues as $index => $value) {
                printf('  #%d %s' . PHP_EOL, $index + 1, $value);
            }
        }
    }

    public function run(Arguments $arguments): int
    {
        $setup     = new Setup;
        $executors = [];

        try {
            $configuration = $setup->workerConfiguration($arguments);
            $phpBinaries   = $setup->phpBinaries($arguments);
            $executors[]   = $first = new ProcessExecutor($configuration, 1, $setup->stderr($arguments));
            $target        = $first->start();
            $choices       = self::choicesIn((string) $arguments->input(), $arguments->raw(), $target);
            $labels        = DifferentialExecutor::labelsOf([$first->php(), ...$phpBinaries]);
            $versions      = [$labels[0] => $first];

            foreach ($phpBinaries as $index => $php) {
                $executors[] = $versions[$labels[$index + 1] ?? $php->path()] = new ProcessExecutor($configuration, 1, $setup->stderr($arguments), $php);
            }

            $results = (new DifferentialExecutor($versions))->executeOnEach(Job::replay(0, $choices, true));
        } catch (ConfigurationException|ProtocolException|WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            foreach ($executors as $executor) {
                $executor->shutdown();
            }
        }

        if ($phpBinaries === []) {
            $result = reset($results);

            self::printResult($result, $arguments->phpunit());

            return $result->isFinding() ? 1 : 0;
        }

        // the input is run on every version of PHP, and its outcomes are
        // compared
        $failed = false;

        foreach ($results as $label => $result) {
            print $label . PHP_EOL;

            self::printResult($result, $arguments->phpunit());

            print PHP_EOL;

            $failed = $failed || $result->isFinding();
        }

        $difference = DifferentialExecutor::differenceOf(0, $results);

        if ($difference->isFinding()) {
            print $difference->failure()->describe() . PHP_EOL;

            return 1;
        }

        print 'The outcome is the same on all versions of PHP.' . PHP_EOL;

        return $failed ? 1 : 0;
    }
}
