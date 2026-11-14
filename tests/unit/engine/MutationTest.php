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
use function array_map;
use function array_pop;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function strlen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

#[CoversClass(RandomChoice::class)]
#[CoversClass(BoundaryChoice::class)]
#[CoversClass(ArithmeticChoice::class)]
#[CoversClass(DeleteSpan::class)]
#[CoversClass(DuplicateSpan::class)]
#[CoversClass(SwapSpans::class)]
#[CoversClass(CrossOver::class)]
#[CoversClass(InsertChoices::class)]
#[CoversClass(DeleteChoices::class)]
#[CoversClass(AddToken::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(RandomElement::class)]
#[UsesClass(Corpus::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Input::class)]
#[UsesClass(Dictionary::class)]
#[Small]
final class MutationTest extends TestCase
{
    private const int ROUNDS = 200;

    public function testNoMutationCanBeAppliedToAnEmptyCandidateExceptInsertingChoices(): void
    {
        $randomizer = $this->randomizer();

        foreach ([new RandomChoice, new BoundaryChoice, new ArithmeticChoice, new DeleteSpan, new DuplicateSpan, new SwapSpans, new CrossOver(new Corpus), new DeleteChoices, new AddToken(new Dictionary(['token']))] as $mutation) {
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

    public function testDeletesAByteThatTheMinimumLengthOfAStringRequiresTogetherWithTheChoiceThatAddedTheFirstOtherByte(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('abc', 2));

            $this->assertSame([0x61, 0x62, 1, 0x63, 0], $candidate->choices());
            $this->assertTrue((new DeleteSpan)->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $this->assertEqualsCanonicalizing(
            [
                // the first byte that the minimum length requires: the byte
                // that a choice added takes the place of the second one
                '98,99,0',
                // the second byte that the minimum length requires
                '97,99,0',
                // the byte that a choice added
                '97,98,0',
                // the span that ends the string
                '97,98,1,99',
                // the whole string
                '',
            ],
            array_keys($seen),
        );
    }

    public function testDeletesAnElementThatTheMinimumSizeOfACollectionRequiresTogetherWithTheChoiceThatAddedTheFirstOtherElement(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            // the first choice of the first element that the minimum size
            // requires is the 1 of its boolean, whose maximum is 1, as that
            // of the choice that adds an element
            $candidate = Candidate::from(Sequences::ofBooleans([true, false, true], 2));

            $this->assertSame([1, 0, 1, 1, 0], $candidate->choices());
            $this->assertTrue((new DeleteSpan)->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $this->assertEqualsCanonicalizing(
            [
                // the first element that the minimum size requires: the
                // element that a choice added takes the place of the second
                // one
                '0,1,0',
                // the second element that the minimum size requires
                '1,1,0',
                // the element that a choice added
                '1,0,0',
                // the span that ends the list
                '1,0,1,1',
                // the whole list
                '',
                // the boolean of the first element, of the second element,
                // and of the element that a choice added
                '0,1,1,0',
                '1,1,1,0',
                '1,0,1,0',
            ],
            array_keys($seen),
        );
    }

    public function testDoesNotDeleteAnElementThatTheMinimumSizeOfACollectionRequiresWhenNoChoiceAddedAnElementToTheCollection(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('ab', 2));

            $this->assertSame([0x61, 0x62, 0], $candidate->choices());

            if ((new DeleteSpan)->apply($candidate, $randomizer)) {
                $seen[implode(',', $candidate->choices())] = true;
            } else {
                $this->assertSame([0x61, 0x62, 0], $candidate->choices());

                $seen['not applied'] = true;
            }
        }

        // the span that ends the string, or the whole string, is deleted
        $this->assertEqualsCanonicalizing(['97,98', '', 'not applied'], array_keys($seen));
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

        // the span that ends the string is not swapped with a byte
        $this->assertSame(['ba'], array_keys($seen));
        $this->assertFalse((new SwapSpans)->apply(Candidate::from(new ChoiceSequence([1, 2], [new Span(0, 2, 'a', null), new Span(1, 2, 'a', 0)], [])), $randomizer));
    }

    public function testSwapsTwoSpansWhenTheLaterOneComesFirstAmongTheSpans(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([1, 2], [new Span(1, 2, 'a', null), new Span(0, 1, 'a', null)], []));

        $this->assertTrue((new SwapSpans)->apply($candidate, $this->randomizer()));
        $this->assertSame([2, 1], $candidate->choices());
    }

    public function testSwapsTheElementsOfACollectionButNotTheSpanThatEndsIt(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofList([3, 5]));

            $this->assertTrue((new SwapSpans)->apply($candidate, $randomizer));
            $this->assertSame([5, 3], Sequences::listFor($candidate->choices()));
        }
    }

    public function testSwapsAByteThatTheMinimumLengthOfAStringRequiresWithAByteThatAChoiceAddedWithoutTheChoiceThatAddedIt(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('abc', 1));

            $this->assertSame([0x61, 1, 0x62, 1, 0x63, 0], $candidate->choices());
            $this->assertTrue((new SwapSpans)->apply($candidate, $randomizer));
            $this->assertSame([[0, 6], [0, 1], [1, 3], [3, 5], [5, 6]], $this->bounds($candidate));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $this->assertEqualsCanonicalizing(
            [
                // the byte that the minimum length requires and the first
                // byte that a choice added
                '98,1,97,1,99,0',
                // the byte that the minimum length requires and the second
                // byte that a choice added
                '99,1,98,1,97,0',
                // the two bytes that a choice added
                '97,1,99,1,98,0',
            ],
            array_keys($seen),
        );
    }

    public function testSwapsAnElementThatTheMinimumSizeOfACollectionRequiresWithAnElementThatAChoiceAddedWithoutTheChoiceThatAddedIt(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            // the first choice of the first element that the minimum size
            // requires is the 1 of its boolean, whose maximum is 1, as that
            // of the choice that adds an element
            $candidate = Candidate::from(Sequences::ofBooleans([true, false, false, true], 2));

            $this->assertSame([1, 0, 1, 0, 1, 1, 0], $candidate->choices());
            $this->assertTrue((new SwapSpans)->apply($candidate, $randomizer));

            // the choices that added the third and the fourth element, and
            // the choice that ends the list, stay where they are
            $this->assertSame(7, $candidate->length());
            $this->assertSame([1, 1, 0], [$candidate->choiceAt(2), $candidate->choiceAt(4), $candidate->choiceAt(6)]);

            $seen[implode(',', array_map(static fn (bool $value): string => $value ? 'true' : 'false', Sequences::booleansFor($candidate->choices(), 2)))] = true;
        }

        $this->assertEqualsCanonicalizing(
            [
                // the two elements that the minimum size requires
                'false,true,false,true',
                // the first element and the third one
                'false,false,true,true',
                // the first element and the fourth one, which have the same
                // value, and the second element and the third one, which have
                // the same value
                'true,false,false,true',
                // the second element and the fourth one
                'true,true,false,false',
                // the two elements that a choice added
                'true,false,true,false',
            ],
            array_keys($seen),
        );
    }

    public function testDuplicatesTheElementsOfACollectionButNotTheSpanThatEndsIt(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofList([3, 5]));

            (new DuplicateSpan)->apply($candidate, $randomizer);

            $seen[implode(',', Sequences::listFor($candidate->choices()))] = true;
        }

        // an element or a run of elements is repeated
        $this->assertContains('3,3,5', array_keys($seen));
        $this->assertContains('3,5,5', array_keys($seen));
        $this->assertContains('3,5,3,5', array_keys($seen));

        // in an empty list, only the list can be duplicated, not the span that
        // ends it
        $candidate = Candidate::from(Sequences::ofList([]));

        $this->assertTrue((new DuplicateSpan)->apply($candidate, $randomizer));
        $this->assertSame([0, 0], $candidate->choices());
        $this->assertSame(
            ['list', 'element', 'list'],
            array_map(static fn (array $span): string => $span['label'], array_values($candidate->spans())),
        );
    }

    public function testDuplicatesTheBytesThatTheMinimumLengthOfAStringRequiresAsBytesThatAChoiceAddedBehindTheLastOfThem(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('abc', 2));

            $this->assertSame([0x61, 0x62, 1, 0x63, 0], $candidate->choices());
            $this->assertTrue((new DuplicateSpan)->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $this->assertEqualsCanonicalizing(
            [
                // the first byte that the minimum length requires
                '97,98,1,97,1,99,0',
                // both bytes that the minimum length requires
                '97,98,1,97,1,98,1,99,0',
                // both bytes that the minimum length requires and the byte
                // that a choice added
                '97,98,1,99,1,97,1,98,1,99,0',
                // the second byte that the minimum length requires
                '97,98,1,98,1,99,0',
                // the second byte that the minimum length requires and the
                // byte that a choice added
                '97,98,1,99,1,98,1,99,0',
                // the byte that a choice added
                '97,98,1,99,1,99,0',
                // the whole string
                '97,98,1,99,0,97,98,1,99,0',
            ],
            array_keys($seen),
        );
    }

    public function testDuplicatesTheElementsThatTheMinimumSizeOfACollectionRequiresAsElementsThatAChoiceAddedBehindTheLastOfThem(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            // the first choice of the first element that the minimum size
            // requires is the 1 of its boolean, whose maximum is 1, as that
            // of the choice that adds an element, and the second element
            // consists of the 0 of its boolean, as the span that ends a
            // collection consists of a 0
            $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2));

            $this->assertSame([1, 0, 0], $candidate->choices());
            $this->assertTrue((new DuplicateSpan)->apply($candidate, $randomizer));

            $choices = implode(',', $candidate->choices());
            $spans   = $candidate->spans();
            $copy    = array_pop($spans);

            $this->assertNotNull($copy);

            $seen[$choices] = true;

            // the copy of an element is an element that a choice added
            $this->assertSame(in_array($choices, ['1,0,1,1,0', '1,0,1,1,1,0,0', '1,0,1,0,0'], true), $candidate->isDecided($copy));
            $this->assertFalse($candidate->isRequired($copy));
        }

        $this->assertEqualsCanonicalizing(
            [
                // the first element
                '1,0,1,1,0',
                // both elements
                '1,0,1,1,1,0,0',
                // the second element
                '1,0,1,0,0',
                // the whole list
                '1,0,0,1,0,0',
                // the boolean of the first element, which the choices of the
                // second element are read as
                '1,1,0,0',
                // the boolean of the second element
                '1,0,0,0',
            ],
            array_keys($seen),
        );
    }

    public function testDoesNotDuplicateTheElementsOfACollectionThatHasItsMaximumSizeAndWhoseMinimumSizeRequiresAllOfItsElements(): void
    {
        $randomizer = $this->randomizer();

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('ab', 2, 2));

            // only the whole string is duplicated
            $this->assertTrue((new DuplicateSpan)->apply($candidate, $randomizer));
            $this->assertSame([0x61, 0x62, 0x61, 0x62], $candidate->choices());
            $this->assertSame([[0, 2], [0, 1], [1, 2], [2, 4]], $this->bounds($candidate));
        }

        // without the span of the string, there is nothing to duplicate
        $candidate = Candidate::from(
            new ChoiceSequence(
                [0x61, 0x62],
                [new Span(0, 1, 'byte', null, SpanKind::RequiredElement), new Span(1, 2, 'byte', null, SpanKind::RequiredElement)],
                [0xFF, 0xFF],
            ),
        );

        $this->assertFalse((new DuplicateSpan)->apply($candidate, $randomizer));
        $this->assertSame([0x61, 0x62], $candidate->choices());
    }

    public function testInsertsACopyOfAnElementAtTheBoundaryBetweenElementsOfACollection(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofList([3, 5]));

            (new InsertChoices)->apply($candidate, $randomizer);

            $seen[implode(',', Sequences::listFor($candidate->choices()))] = true;
        }

        foreach (['3,3,5', '3,5,5', '5,3,5', '3,5,3'] as $list) {
            $this->assertContains($list, array_keys($seen));
        }
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

    public function testReplacesNoSpanWhenNoOtherCorpusEntryHasASpanWithTheSameLabel(): void
    {
        $corpus = new Corpus;

        $corpus->add(new ChoiceSequence([7], [new Span(0, 1, 'other', null)], []), [1]);

        $candidate = Candidate::from(new ChoiceSequence([1], [new Span(0, 1, 'label', null)], []));

        $this->assertFalse((new CrossOver($corpus))->apply($candidate, $this->randomizer()));
        $this->assertSame([1], $candidate->choices());
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

    public function testAddsATokenToABinaryStringByInsertingItOrByOverwritingBytes(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            $this->assertTrue((new AddToken(new Dictionary(['XY'])))->apply($candidate, $randomizer));

            $bytes = BytesEncoding::bytesFor($candidate->choices(), 100);

            $this->assertTrue(str_contains($bytes, 'XY'));

            $seen[$bytes] = true;
        }

        // inserted at the boundaries between the bytes, or overwriting the
        // bytes from the first or the second one on
        $this->assertEqualsCanonicalizing(['XYab', 'aXYb', 'abXY', 'XY', 'aXY'], array_keys($seen));
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

        $this->assertTrue((new AddToken(new Dictionary(['XY'])))->apply($candidate, $this->randomizer()));
        $this->assertSame([1, 1, 0x58, 1, 0x59, 0, 3], $candidate->choices());

        $candidate = Candidate::from(Sequences::drawn([1], static function (Input $input): void
        {
            $input->bool();
            $input->int(0, 1);
        }));

        $this->assertFalse((new AddToken(new Dictionary(['XY'])))->apply($candidate, $this->randomizer()));
    }

    public function testAddsATokenToAStringOfUnicodeCharactersAsCharacters(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofCharacters('a'));

            $this->assertTrue((new AddToken(new Dictionary(['X€'])))->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $x    = CodePoints::index(0x58);
        $euro = CodePoints::index(0x20AC);
        $a    = CodePoints::index(0x61);

        // inserted before or after the character, or overwriting it
        $this->assertEqualsCanonicalizing(
            [
                implode(',', [1, $x, 1, $euro, 1, $a, 0]),
                implode(',', [1, $a, 1, $x, 1, $euro, 0]),
                implode(',', [1, $x, 1, $euro, 0]),
            ],
            array_keys($seen),
        );
    }

    public function testInsertsNoTokenThatIsNotValidUtf8IntoAStringOfUnicodeCharacters(): void
    {
        $candidate = Candidate::from(Sequences::ofCharacters('a'));

        $this->assertFalse((new AddToken(new Dictionary(["\x89PNG"])))->apply($candidate, $this->randomizer()));
        $this->assertSame([1, CodePoints::index(0x61), 0], $candidate->choices());
    }

    public function testAddsATokenToAStringWithAMinimumLengthByInsertingItAfterTheBytesThatItRequiresOrByOverwritingThem(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('ab', 1));

            $this->assertTrue((new AddToken(new Dictionary(['XY'])))->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        // inserted before the byte that a choice added or before the span
        // that ends the string, but never before the byte that the minimum
        // length requires, which is overwritten instead
        $this->assertEqualsCanonicalizing(
            ['97,1,88,1,89,1,98,0', '97,1,98,1,88,1,89,0', '88,1,89,0', '97,1,88,1,89,0'],
            array_keys($seen),
        );
    }

    public function testAddsATokenToAStringOfUnicodeCharactersWithAMinimumLengthByInsertingItAfterTheCharactersThatItRequiresOrByOverwritingThem(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofCharacters('ab', 1));

            $this->assertTrue((new AddToken(new Dictionary(['XY'])))->apply($candidate, $randomizer));

            $seen[implode(',', $candidate->choices())] = true;
        }

        $a = CodePoints::index(0x61);
        $b = CodePoints::index(0x62);
        $x = CodePoints::index(0x58);
        $y = CodePoints::index(0x59);

        // inserted before the character that a choice added or before the
        // span that ends the string, but never before the character that the
        // minimum length requires, which is overwritten instead
        $this->assertEqualsCanonicalizing(
            [
                implode(',', [$a, 1, $x, 1, $y, 1, $b, 0]),
                implode(',', [$a, 1, $b, 1, $x, 1, $y, 0]),
                implode(',', [$x, 1, $y, 0]),
                implode(',', [$a, 1, $x, 1, $y, 0]),
            ],
            array_keys($seen),
        );
    }

    public function testInsertsBytesBehindTheBytesThatTheMinimumLengthOfAStringRequires(): void
    {
        $randomizer    = $this->randomizer();
        $seen          = [];
        $insertedBytes = 0;

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('abc', 2));

            $this->assertTrue((new InsertChoices)->apply($candidate, $randomizer));

            $bounds = $this->bounds($candidate);

            // random choices are inserted at the boundaries of the span of
            // the whole string
            if (count($bounds) === 5) {
                continue;
            }

            // the bytes that the minimum length requires stay where they are,
            // and bytes are inserted before or after the byte that a choice
            // added, also when the span of a byte that the minimum length
            // requires was chosen
            $choices = $candidate->choices();

            $this->assertSame([0x61, 0x62], array_slice($choices, 0, 2));
            $this->assertSame([[0, 1], [1, 2]], array_slice($bounds, 1, 2));
            $this->assertSame(0, array_pop($choices));

            for ($position = 2; $position < count($choices); $position += 2) {
                $this->assertSame(1, $candidate->choiceAt($position));
                $this->assertSame(1, $candidate->maximumAt($position));
                $this->assertSame(0xFF, $candidate->maximumAt($position + 1));
            }

            // the byte that a choice added
            if (array_slice($bounds, 3, 1) === [[2, 4]]) {
                $seen['after'] = true;
            } else {
                $seen['before'] = true;
            }

            $insertedBytes++;
        }

        $this->assertEqualsCanonicalizing(['after', 'before'], array_keys($seen));
        $this->assertGreaterThan(self::ROUNDS / 2, $insertedBytes);
    }

    public function testInsertsNothingAtTheBytesOfAStringThatHasItsMaximumLengthAndWhoseMinimumLengthRequiresAllOfItsBytes(): void
    {
        $randomizer = $this->randomizer();
        $applied    = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('ab', 2, 2));

            if ((new InsertChoices)->apply($candidate, $randomizer)) {
                // random choices are inserted at the boundaries of the span
                // of the whole string
                $this->assertGreaterThan(2, $candidate->length());

                $applied['applied'] = true;
            } else {
                $this->assertSame([0x61, 0x62], $candidate->choices());

                $applied['not applied'] = true;
            }

            // bytes that are inserted begin with the choice 1, whose maximum is 1
            for ($position = 0; $position < $candidate->length(); $position++) {
                $this->assertNotSame(1, $candidate->maximumAt($position));
            }
        }

        $this->assertEqualsCanonicalizing(['applied', 'not applied'], array_keys($applied));
    }

    public function testInsertsACopyOfAnElementBehindTheElementsThatTheMinimumSizeOfACollectionRequires(): void
    {
        $randomizer = $this->randomizer();
        $seen       = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            // the first choice of an element that the minimum size requires
            // is the 1 of its boolean, whose maximum is 1, as that of the
            // choice that adds an element
            $candidate = Candidate::from(Sequences::ofBooleans([true, true, false], 2));

            $this->assertSame([1, 1, 1, 0, 0], $candidate->choices());
            $this->assertTrue((new InsertChoices)->apply($candidate, $randomizer));

            $bounds = $this->bounds($candidate);

            // random choices are inserted at the boundaries of the spans of
            // the list and of the booleans
            if (count($bounds) === 8) {
                continue;
            }

            // the elements that the minimum size requires and their booleans
            // stay where they are, and the copy of an element is inserted
            // before or after the element that a choice added, as an element
            // that a choice added
            $this->assertCount(9, $bounds);
            $this->assertSame([[0, 1], [0, 1], [1, 2], [1, 2]], array_slice($bounds, 1, 4));
            $this->assertSame(7, $candidate->length());
            $this->assertSame([1, 1, 1, 1, 1, 1, 1], array_map($candidate->maximumAt(...), [0, 1, 2, 3, 4, 5, 6]));

            $seen[implode(',', array_map(static fn (bool $value): string => $value ? 'true' : 'false', Sequences::booleansFor($candidate->choices(), 2)))] = true;
        }

        $this->assertEqualsCanonicalizing(
            ['true,true,true,false', 'true,true,false,false', 'true,true,false,true'],
            array_keys($seen),
        );
    }

    public function testInsertsNothingAtTheElementsOfACollectionThatHasItsMaximumSizeAndWhoseMinimumSizeRequiresAllOfItsElements(): void
    {
        $randomizer = $this->randomizer();
        $applied    = [];

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2, 2));

            if ((new InsertChoices)->apply($candidate, $randomizer)) {
                // random choices are inserted at the boundaries of the spans
                // of the list and of the booleans
                $this->assertGreaterThan(2, $candidate->length());

                $applied['applied'] = true;
            } else {
                $this->assertSame([1, 0], $candidate->choices());

                $applied['not applied'] = true;
            }

            // no element is inserted
            $this->assertCount(5, $candidate->spans());
        }

        $this->assertEqualsCanonicalizing(['applied', 'not applied'], array_keys($applied));
    }

    public function testInsertsNoTokenWhileTheDictionaryIsEmpty(): void
    {
        $dictionary = new Dictionary;
        $mutation   = new AddToken($dictionary);

        $this->assertFalse($mutation->apply(Candidate::from(Sequences::ofBytes('ab')), $this->randomizer()));

        $dictionary->addFoundTokens(['XY']);

        $this->assertTrue($mutation->apply(Candidate::from(Sequences::ofBytes('ab')), $this->randomizer()));
    }

    public function testInsertsATokenOnlyWhenASentinelIsDrawnWhileTheDictionaryHasNoOtherTokens(): void
    {
        $mutation   = new AddToken(new Dictionary([], ['SENTINEL']));
        $randomizer = $this->randomizer();
        $applied    = [];

        for ($i = 0; $i < 1000; $i++) {
            $candidate = Candidate::from(Sequences::ofBytes('ab'));

            if ($mutation->apply($candidate, $randomizer)) {
                $this->assertStringContainsString('SENTINEL', BytesEncoding::bytesFor($candidate->choices(), 100));

                $applied['applied'] = true;
            } else {
                $this->assertSame([1, 0x61, 1, 0x62, 0], $candidate->choices());

                $applied['not applied'] = true;
            }
        }

        $this->assertEqualsCanonicalizing(['applied', 'not applied'], array_keys($applied));
    }

    /**
     * @return list<array{0: non-negative-int, 1: non-negative-int}>
     */
    private function bounds(Candidate $candidate): array
    {
        $bounds = [];

        foreach ($candidate->spans() as $span) {
            $bounds[] = [$span['start'], $span['end']];
        }

        return $bounds;
    }

    private function randomizer(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(1));
    }
}
