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

use function array_fill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

#[CoversClass(Scheduler::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[Small]
final class SchedulerTest extends TestCase
{
    public function testWeighsEntriesByNoveltyBrevityAndRecency(): void
    {
        $small  = new CorpusEntry(new ChoiceSequence([]), [1], 0);
        $large  = new CorpusEntry(new ChoiceSequence(array_fill(0, 96, 0)), [1], 0);
        $novel  = new CorpusEntry(new ChoiceSequence([]), [1, 2, 3], 0);
        $recent = new CorpusEntry(new ChoiceSequence([]), [1], 9);

        $this->assertEqualsWithDelta(2.0 * 1.0 * 1.4, Scheduler::weightOf($small, 10), 0.0001);
        $this->assertEqualsWithDelta(2.0 * 0.25 * 1.4, Scheduler::weightOf($large, 10), 0.0001);
        $this->assertEqualsWithDelta(3.0 * 1.0 * 1.4, Scheduler::weightOf($novel, 10), 0.0001);
        $this->assertEqualsWithDelta(2.0 * 1.0 * 5.0, Scheduler::weightOf($recent, 10), 0.0001);
    }

    public function testChoosesEntriesInProportionToTheirWeights(): void
    {
        $corpus = new Corpus;

        $corpus->add(new ChoiceSequence(array_fill(0, 96, 0)), [1]);
        $corpus->add(new ChoiceSequence([]), [2]);

        $scheduler  = new Scheduler($corpus);
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $first      = 0;

        for ($i = 0; $i < 10000; $i++) {
            if ($scheduler->choose($randomizer)->index() === 0) {
                $first++;
            }
        }

        // weights: 2 * 0.25 * 3 = 1.5 for the first, 2 * 1 * 5 = 10 for the second
        $this->assertEqualsWithDelta(10000 * 1.5 / 11.5, $first, 300);

        // the weights are computed again when the corpus has changed
        $corpus->add(new ChoiceSequence([]), [3]);

        $this->assertSame(2, $scheduler->choose(new Randomizer(new Xoshiro256StarStar(3)))->index());
    }
}
