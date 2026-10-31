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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

#[CoversClass(RunCommand::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[Small]
final class RunCommandTest extends TestCase
{
    public function testPrintsTheDrawnValuesOfAnInput(): void
    {
        $this->expectOutputString("Outcome: passed\n\nDrawn values:\n  #1 'a'\n  #2 3\n");

        RunCommand::printResult(new Result(0, Outcome::Passed, null, new ChoiceSequence([]), [], ["'a'", '3']));
    }

    public function testPrintsTheValuesOfAnExampleOfAPropertyAsPhpunitPrintsThem(): void
    {
        $this->expectOutputString("Outcome: failed\n\nFailure: RuntimeException at src/Parser.php:12\n\nValues:\n  \$values = [\n      'a',\n  ];\n\n  Draw 1 (digit): 3\n");

        RunCommand::printResult(
            new Result(0, Outcome::Failed, Failure::throwable('RuntimeException', 'src/Parser.php:12', '', ''), new ChoiceSequence([]), [], ["\$values = [\n    'a',\n];", '', 'Draw 1 (digit): 3']),
            true,
        );
    }
}
