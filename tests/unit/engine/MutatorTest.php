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
use function array_map;
use function count;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

#[CoversClass(Mutator::class)]
#[CoversClass(Mutant::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(RandomElement::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Input::class)]
#[UsesClass(RandomChoice::class)]
#[UsesClass(BoundaryChoice::class)]
#[UsesClass(ArithmeticChoice::class)]
#[UsesClass(DeleteSpan::class)]
#[UsesClass(DuplicateSpan::class)]
#[UsesClass(SwapSpans::class)]
#[UsesClass(CrossOver::class)]
#[UsesClass(InsertChoices::class)]
#[UsesClass(DeleteChoices::class)]
#[UsesClass(AddToken::class)]
#[UsesClass(Dictionary::class)]
#[Small]
final class MutatorTest extends TestCase
{
    public function testMutatesTheChoicesOfACorpusEntryDeterministically(): void
    {
        $corpus = new Corpus;
        $entry  = $corpus->add(Sequences::ofBytes('hello world'), [1]);

        $this->assertNotNull($entry);

        $first  = $this->mutations(Mutator::create($corpus, new Dictionary(['RATL']), 8192), $entry, 1);
        $second = $this->mutations(Mutator::create($corpus, new Dictionary(['RATL']), 8192), $entry, 1);
        $third  = $this->mutations(Mutator::create($corpus, new Dictionary(['RATL']), 8192), $entry, 2);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $third);
        $this->assertContains(true, array_map(static fn (array $choices): bool => $choices !== $entry->sequence()->choices(), $first));
    }

    public function testDoesNotExceedTheMaximumNumberOfChoices(): void
    {
        $corpus = new Corpus;
        $entry  = $corpus->add(new ChoiceSequence(array_fill(0, 10, 1)), [1]);

        $this->assertNotNull($entry);

        foreach ($this->mutations(new Mutator([new InsertChoices], 10), $entry, 1) as $choices) {
            $this->assertLessThanOrEqual(10, count($choices));
        }
    }

    public function testKeepsTheNamesOfTheMutationsThatWereApplied(): void
    {
        $corpus = new Corpus;
        $entry  = $corpus->add(new ChoiceSequence(array_fill(0, 10, 1)), [1]);

        $this->assertNotNull($entry);

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        for ($i = 0; $i < 100; $i++) {
            $mutations = (new Mutator([new InsertChoices], 8192))->mutate($entry, $randomizer)->mutations();

            $this->assertGreaterThanOrEqual(1, count($mutations));
            $this->assertLessThanOrEqual(5, count($mutations));
            $this->assertSame(array_fill(0, count($mutations), 'InsertChoices'), $mutations);
        }
    }

    public function testKeepsNoNameOfAMutationThatCouldNotBeApplied(): void
    {
        $corpus = new Corpus;
        $entry  = $corpus->add(new ChoiceSequence([1, 2, 3]), [1]);

        $this->assertNotNull($entry);

        $mutant = (new Mutator([new DeleteSpan], 8192))->mutate($entry, new Randomizer(new Xoshiro256StarStar(1)));

        $this->assertSame([], $mutant->mutations());
        $this->assertSame([1, 2, 3], $mutant->choices());
    }

    /**
     * @return list<list<non-negative-int>>
     */
    private function mutations(Mutator $mutator, CorpusEntry $entry, int $seed): array
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $mutations  = [];

        for ($i = 0; $i < 100; $i++) {
            $mutations[] = $mutator->mutate($entry, $randomizer)->choices();
        }

        return $mutations;
    }
}
