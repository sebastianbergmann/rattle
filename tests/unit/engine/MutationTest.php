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

use const PHP_INT_MAX;
use function array_keys;
use function count;
use function str_contains;
use function strlen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Span;

#[CoversClass(RandomChoice::class)]
#[CoversClass(BoundaryChoice::class)]
#[CoversClass(ArithmeticChoice::class)]
#[CoversClass(DeleteSpan::class)]
#[CoversClass(DuplicateSpan::class)]
#[CoversClass(SwapSpans::class)]
#[CoversClass(CrossOver::class)]
#[CoversClass(InsertChoices::class)]
#[CoversClass(DeleteChoices::class)]
#[CoversClass(InsertToken::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(RandomElement::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Input::class)]
#[Small]
final class MutationTest extends TestCase
{
    private const int ROUNDS = 200;

    public function testNoMutationCanBeAppliedToAnEmptyCandidateExceptInsertingChoices(): void
    {
        $randomizer = $this->randomizer();

        foreach ([new RandomChoice, new BoundaryChoice, new ArithmeticChoice, new DeleteSpan, new DuplicateSpan, new SwapSpans, new CrossOver(new Corpus), new DeleteChoices, new InsertToken(['token'])] as $mutation) {
            $this->assertFalse($mutation->apply(Candidate::from(new ChoiceSequence([])), $randomizer), $mutation::class);
        }

        $candidate = Candidate::from(new ChoiceSequence([]));

        $this->assertTrue((new InsertChoices)->apply($candidate, $randomizer));
        $this->assertGreaterThan(0, $candidate->length());
    }

    public function testSetsAChoiceToARandomValueUpToItsMaximum(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(new ChoiceSequence([0, 0], [], [3, 1]));

            (new RandomChoice)->apply($candidate, $randomizer);

            $this->assertLessThanOrEqual(3, $candidate->choiceAt(0));
            $this->assertLessThanOrEqual(1, $candidate->choiceAt(1));
        }
    }

    public function testFlipsALowBitOfAChoiceWhoseMaximumIsNotKnown(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([0]));

        (new RandomChoice)->apply($candidate, $this->randomizer());

        $this->assertContains($candidate->choiceAt(0), [1, 2, 4, 8, 16, 32, 64, 128]);
    }

    public function testSetsAChoiceToZeroOrItsMaximum(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(new ChoiceSequence([5, 5], [], [9, 9]));

            (new BoundaryChoice)->apply($candidate, $randomizer);

            foreach ($candidate->choices() as $choice) {
                $seen[$choice] = true;
            }
        }

        $this->assertEqualsCanonicalizing([0, 5, 9], array_keys($seen));

        $candidate = Candidate::from(new ChoiceSequence([5]));

        for ($i = 0; $i < 10 && $candidate->choiceAt(0) !== PHP_INT_MAX; $i++) {
            (new BoundaryChoice)->apply($candidate, $randomizer);
        }

        $this->assertSame(PHP_INT_MAX, $candidate->choiceAt(0));
    }

    public function testAddsOrSubtractsASmallAmountWithinTheRange(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(new ChoiceSequence([2, 254, PHP_INT_MAX - 1], [], [10, 255, PHP_INT_MAX]));

            (new ArithmeticChoice)->apply($candidate, $randomizer);

            $this->assertLessThanOrEqual(10, $candidate->choiceAt(0));
            $this->assertLessThanOrEqual(255, $candidate->choiceAt(1));
            $this->assertGreaterThanOrEqual(0, $candidate->choiceAt(2));
        }
    }

    public function testDeletesASpan(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('abc'));

            (new DeleteSpan)->apply($candidate, $randomizer);

            $this->assertContains(
                BytesEncoding::bytesFor($candidate->choices(), 100),
                ['', 'bc', 'ac', 'ab', 'abc'],
            );
        }
    }

    public function testDuplicatesASpan(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('abc'));

            (new DuplicateSpan)->apply($candidate, $randomizer);

            $this->assertContains(
                BytesEncoding::bytesFor($candidate->choices(), 100),
                // a byte, or a run of bytes that starts with it, is repeated
                ['aabc', 'ababc', 'abcabc', 'abbc', 'abcbc', 'abcc', 'abc'],
            );
        }
    }

    public function testSwapsTwoSpansWithTheSameLabelAndParent(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            $this->assertTrue((new SwapSpans)->apply($candidate, $randomizer));

            $seen[BytesEncoding::bytesFor($candidate->choices(), 100)] = true;
        }

        // the bytes are swapped, or the byte and the span that ends the string
        $this->assertArrayHasKey('ba', $seen);
        $this->assertArrayHasKey('', $seen);
        $this->assertFalse((new SwapSpans)->apply(Candidate::from(new ChoiceSequence([1, 2], [new Span(0, 2, 'a', null), new Span(1, 2, 'a', 0)], [])), $randomizer));
    }

    public function testReplacesASpanWithASpanWithTheSameLabelFromAnotherCorpusEntry(): void
    {
        $corpus = new Corpus;

        $corpus->add(Sequences::ofBytes('XYZ'), [1]);

        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            (new CrossOver($corpus))->apply($candidate, $randomizer);

            $bytes = BytesEncoding::bytesFor($candidate->choices(), 100);

            $this->assertTrue(strlen($bytes) <= 4, $bytes);
        }

        $candidate = Candidate::from(Sequences::ofBytes('ab'));

        for ($i = 0; $i < self::ROUNDS && BytesEncoding::bytesFor($candidate->choices(), 100) !== 'XYZ'; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            (new CrossOver($corpus))->apply($candidate, $randomizer);
        }

        $this->assertSame('XYZ', BytesEncoding::bytesFor($candidate->choices(), 100));
    }

    public function testInsertsBytesAtTheBoundaryBetweenBytes(): void
    {
        $randomizer    = $this->randomizer();
        $insertedBytes = 0;

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            (new InsertChoices)->apply($candidate, $randomizer);

            // bytes are inserted as spans of their own, unless the span of the
            // whole string is chosen, at whose boundaries random choices are
            // inserted
            if (count($candidate->spans()) > 4) {
                $this->assertGreaterThan(2, strlen(BytesEncoding::bytesFor($candidate->choices(), 100)));

                $insertedBytes++;
            }
        }

        $this->assertGreaterThan(self::ROUNDS / 2, $insertedBytes);
    }

    public function testInsertsRandomChoicesAtTheBoundaryOfOtherSpans(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([5], [new Span(0, 1, 'value', null)], [9]));

        (new InsertChoices)->apply($candidate, $this->randomizer());

        $this->assertGreaterThan(1, $candidate->length());
        $this->assertContains(5, $candidate->choices());
    }

    public function testDeletesABlockOfChoicesOrCutsOffTheTail(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(new ChoiceSequence([1, 2, 3, 4, 5]));

            (new DeleteChoices)->apply($candidate, $randomizer);

            $this->assertLessThan(5, $candidate->length());
        }
    }

    public function testInsertsATokenAtTheBoundaryBetweenBytes(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            $this->assertTrue((new InsertToken(['XY']))->apply($candidate, $randomizer));

            $bytes = BytesEncoding::bytesFor($candidate->choices(), 100);

            $this->assertTrue(str_contains($bytes, 'XY'));

            $seen[$bytes] = true;
        }

        $this->assertEqualsCanonicalizing(['XYab', 'aXYb', 'abXY'], array_keys($seen));
    }

    public function testInsertsATokenIntoAnEmptyString(): void
    {
        $draw = static function (Input $input): void
        {
            $input->bool();
            $input->bytes(10);
            $input->int(0, 5);
        };

        $candidate = Candidate::from(Sequences::drawn([1, 0, 3], $draw));

        $this->assertTrue((new InsertToken(['XY']))->apply($candidate, $this->randomizer()));
        $this->assertSame([1, 1, 0x58, 1, 0x59, 0, 3], $candidate->choices());

        $candidate = Candidate::from(Sequences::drawn([1], static function (Input $input): void
        {
            $input->bool();
            $input->int(0, 1);
        }));

        $this->assertFalse((new InsertToken(['XY']))->apply($candidate, $this->randomizer()));
    }

    private function randomizer(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(1));
    }
}
