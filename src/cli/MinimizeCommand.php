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
use const SIGINT;
use function count;
use function file_put_contents;
use function pcntl_async_signals;
use function pcntl_signal;
use function printf;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Encoder;

/**
 * Minimizes one input that makes the target fail and writes the minimal
 * input to a file next to it, whose name ends with ".minimized".
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class MinimizeCommand implements Command
{
    public function run(Arguments $arguments): int
    {
        $setup       = new Setup;
        $executor    = null;
        $interrupted = false;

        pcntl_async_signals(true);
        pcntl_signal(
            SIGINT,
            static function () use (&$interrupted): void
            {
                $interrupted = true;
            },
        );

        try {
            $executor = new ProcessExecutor($setup->workerConfiguration($arguments), 1, $setup->stderr($arguments));
            $target   = $executor->start();
            $input    = new ChoiceSequence(RunCommand::choicesIn((string) $arguments->input(), false, $target));
            $result   = $executor->execute(Job::replay(0, $input->choices()));

            if (!$result->isFinding()) {
                print 'The input does not make the target fail.' . PHP_EOL;

                return 1;
            }

            $minimization = (new Minimizer($executor, $arguments->maximumNumberOfShrinkingAttempts(), static fn (): bool => $interrupted))
                ->minimize($result->sequence(), $result->failure());
        } catch (ConfigurationException|ProtocolException|WorkerException $e) {
            print $e->getMessage() . PHP_EOL;

            return 2;
        } finally {
            $executor?->shutdown();
        }

        $output = $arguments->input() . '.minimized';

        if (file_put_contents($output, (new Encoder)->encode(new ChoiceSequence($minimization->minimal()->choices()))) === false) {
            printf('Cannot write "%s"' . PHP_EOL, $output);

            return 2;
        }

        printf('Failure: %s' . PHP_EOL, $minimization->failure()->describe());

        if ($minimization->isFlaky()) {
            print 'The input did not fail in the same way again when it was replayed, and was not minimized.' . PHP_EOL;
        } elseif (!$minimization->minimalInputReproduced()) {
            print 'The minimal input did not fail in the same way again when it was replayed, so the input is kept.' . PHP_EOL;
        } else {
            printf(
                'Minimized from %d to %d choices.' . PHP_EOL,
                count($minimization->original()->choices()),
                count($minimization->minimal()->choices()),
            );
        }

        printf('Wrote %s' . PHP_EOL, $output);

        return 0;
    }
}
