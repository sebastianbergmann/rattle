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

use function array_map;
use function array_values;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\Bytes;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\CodePoints;
use SebastianBergmann\ChoiceSequence\Label;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

#[CoversClass(Candidate::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Input::class)]
#[Small]
final class CandidateTest extends TestCase
{
    public function testIsCreatedFromAChoiceSequence(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('ab'));

        $this->assertSame([1, 0x61, 1, 0x62, 0], $candidate->choices());
        $this->assertSame(5, $candidate->length());
        $this->assertSame(0x62, $candidate->choiceAt(3));
        $this->assertSame(0xFF, $candidate->maximumAt(3));
        $this->assertNull($candidate->maximumAt(5));
        $this->assertSame(
            [
                [0, 5],
                [0, 2],
                [2, 4],
                [4, 5],
            ],
            $this->bounds($candidate),
        );
        $spans = array_values($candidate->spans());

        $this->assertSame(
            [false, true, true, true],
            array_map(static fn (array $span): bool => $candidate->isByteOfString($span), $spans),
        );
    }

    public function testKnowsTheSpansOfTheCharactersOfAStringOfUnicodeCharacters(): void
    {
        $candidate = Candidate::from(Sequences::ofCharacters('a€'));
        $spans     = array_values($candidate->spans());

        $this->assertSame([1, CodePoints::index(0x61), 1, CodePoints::index(0x20AC), 0], $candidate->choices());
        $this->assertSame(
            [false, true, true, true],
            array_map(static fn (array $span): bool => $candidate->isCharacterOfString($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isByteOfString($span), $spans),
        );
    }

    public function testKnowsWhichElementsOfAStringAreDecidedByAChoice(): void
    {
        $candidate = Candidate::from(Sequences::ofBytesWithMinimumLength('ab', 1));
        $spans     = array_values($candidate->spans());

        $this->assertSame([0x61, 1, 0x62, 0], $candidate->choices());
        // the string, the byte that the minimum length requires, the byte
        // that a choice added, and the span that ends the string
        $this->assertSame(
            [false, false, true, true],
            array_map(static fn (array $span): bool => $candidate->isDecided($span), $spans),
        );
        $this->assertSame(
            [false, true, false, false],
            array_map(static fn (array $span): bool => $candidate->isRequired($span), $spans),
        );
        $this->assertSame(
            [false, true, true, true],
            array_map(static fn (array $span): bool => $candidate->isByteOfString($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isCharacterOfString($span), $spans),
        );
    }

    public function testKnowsTheSpansOfTheCharactersThatTheMinimumLengthOfAStringOfUnicodeCharactersRequires(): void
    {
        $candidate = Candidate::from(Sequences::ofCharacters('a€', 1));
        $spans     = array_values($candidate->spans());

        $this->assertSame([CodePoints::index(0x61), 1, CodePoints::index(0x20AC), 0], $candidate->choices());
        // the string, the character that the minimum length requires, the
        // character that a choice added, and the span that ends the string
        $this->assertSame(
            [false, true, true, true],
            array_map(static fn (array $span): bool => $candidate->isCharacterOfString($span), $spans),
        );
        $this->assertSame(
            [false, true, false, false],
            array_map(static fn (array $span): bool => $candidate->isRequired($span), $spans),
        );
        $this->assertSame(
            [false, false, true, true],
            array_map(static fn (array $span): bool => $candidate->isDecided($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isByteOfString($span), $spans),
        );
    }

    public function testAnElementThatTheMinimumSizeOfACollectionRequiresIsNotDecidedByAChoiceEvenWhenItsFirstChoiceIsAOneWhoseMaximumIsOne(): void
    {
        $candidate = Candidate::from(Sequences::ofBooleans([true, true], 1));
        $spans     = array_values($candidate->spans());

        $this->assertSame([1, 1, 1, 0], $candidate->choices());
        $this->assertSame([1, 1, 1, 1], array_map($candidate->maximumAt(...), [0, 1, 2, 3]));
        // the list, the element that the minimum size requires and its
        // boolean, the element that a choice added and its boolean, and the
        // span that ends the list
        $this->assertSame([[0, 4], [0, 1], [0, 1], [1, 3], [2, 3], [3, 4]], $this->bounds($candidate));
        $this->assertSame(
            [false, true, false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isRequired($span), $spans),
        );
        $this->assertSame(
            [false, false, false, true, false, true],
            array_map(static fn (array $span): bool => $candidate->isDecided($span), $spans),
        );
        $this->assertSame(
            [false, true, false, true, false, false],
            array_map(static fn (array $span): bool => $candidate->isElement($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false, false, true],
            array_map(static fn (array $span): bool => $candidate->endsCollection($span), $spans),
        );
        $end = $spans[5] ?? null;

        $this->assertNotNull($end);
        $this->assertSame(
            [[0, 1], [1, 3]],
            array_map(static fn (array $span): array => [$span['start'], $span['end']], $candidate->elementsBesides($end)),
        );
    }

    public function testAnElementThatTheMinimumSizeOfACollectionRequiresDoesNotEndItEvenWhenItConsistsOfAZeroWhoseMaximumIsOne(): void
    {
        $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2, 2));
        $spans     = array_values($candidate->spans());

        $this->assertSame([1, 0], $candidate->choices());
        // the list, and the two elements that the minimum size requires with
        // their booleans: a list that has its maximum size has no span that
        // ends it
        $this->assertSame([[0, 2], [0, 1], [0, 1], [1, 2], [1, 2]], $this->bounds($candidate));
        $this->assertSame(
            [false, false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->endsCollection($span), $spans),
        );
        $this->assertSame(
            [false, true, false, true, false],
            array_map(static fn (array $span): bool => $candidate->isElement($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isDecided($span), $spans),
        );
    }

    public function testKnowsTheElementsOfCollectionsAndTheSpansThatEndThem(): void
    {
        $candidate = Candidate::from(Sequences::ofList([3, 5]));
        $spans     = array_values($candidate->spans());

        // the list, the first element and its integer, the second element and
        // its integer, and the span that ends the list
        $this->assertSame(
            [false, true, false, true, false, false],
            array_map(static fn (array $span): bool => $candidate->isElement($span), $spans),
        );
        $this->assertSame(
            [false, false, false, false, false, true],
            array_map(static fn (array $span): bool => $candidate->endsCollection($span), $spans),
        );
        $end = $spans[5] ?? null;

        $this->assertNotNull($end);
        $this->assertSame(
            [[0, 2], [2, 4]],
            array_map(static fn (array $span): array => [$span['start'], $span['end']], $candidate->elementsBesides($end)),
        );
        $this->assertSame(
            [false, false, false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isByteOfString($span), $spans),
        );
    }

    public function testKnowsThePositionBehindTheElementsThatTheMinimumSizeOfACollectionRequires(): void
    {
        $candidate = Candidate::from(Sequences::ofBooleans([true, true, false], 2));

        // the list, the two elements that the minimum size requires and
        // their booleans, the element that a choice added and its boolean,
        // and the span that ends the list
        $this->assertSame([[0, 5], [0, 1], [0, 1], [1, 2], [1, 2], [2, 4], [3, 4], [4, 5]], $this->bounds($candidate));

        foreach ([1, 3, 5, 7] as $index) {
            $span = array_values($candidate->spans())[$index] ?? null;

            $this->assertNotNull($span);
            $this->assertSame(2, $candidate->positionBehindRequiredElements($span));
        }

        // the span that ends the list
        $candidate = Candidate::from(Sequences::ofBooleans([true], 1));
        $span      = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($span);
        $this->assertSame(1, $candidate->positionBehindRequiredElements($span));

        // a list without elements that the minimum size requires
        $candidate = Candidate::from(Sequences::ofList([3]));
        $span      = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($span);
        $this->assertSame(0, $candidate->positionBehindRequiredElements($span));
    }

    public function testACollectionThatHasItsMaximumSizeAndWhoseMinimumSizeRequiresAllOfItsElementsHasNoPositionBehindThem(): void
    {
        $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2, 2));
        $span      = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($span);
        $this->assertNull($candidate->positionBehindRequiredElements($span));
    }

    public function testASpanThatIsFollowedByAnotherElementOfItsCollectionDoesNotEndIt(): void
    {
        $candidate = Candidate::from(Sequences::ofList([3]));
        $end       = array_values($candidate->spans())[3] ?? null;

        $this->assertNotNull($end);
        $this->assertTrue($candidate->endsCollection($end));

        $candidate->insert(3, [1, 5]);
        $candidate->addSpan(3, 5, 'element', $end['parent'], SpanKind::Element);

        $this->assertSame([1, 3, 0, 1, 5], $candidate->choices());
        $this->assertSame([[0, 3], [0, 2], [1, 2], [2, 3], [3, 5]], $this->bounds($candidate));
        $this->assertFalse($candidate->endsCollection($end));
    }

    public function testSetsAChoice(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('ab'));

        $candidate->set(1, 0x7A);

        $this->assertSame([1, 0x7A, 1, 0x62, 0], $candidate->choices());
    }

    public function testDeletingChoicesMovesAndShrinksSpans(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('abc'));

        $candidate->delete(2, 2);

        $this->assertSame([1, 0x61, 1, 0x63, 0], $candidate->choices());
        $this->assertSame([[0, 5], [0, 2], [2, 4], [4, 5]], $this->bounds($candidate));
        $this->assertSame([1, 0xFF, 1, 0xFF, 1], [
            $candidate->maximumAt(0),
            $candidate->maximumAt(1),
            $candidate->maximumAt(2),
            $candidate->maximumAt(3),
            $candidate->maximumAt(4),
        ]);
    }

    public function testDeletingPartOfASpanShrinksIt(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([1, 2, 3, 4], [new Span(1, 3, 'a', null)], [9, 9, 9, 9]));

        $candidate->delete(2, 2);

        $this->assertSame([1, 2], $candidate->choices());
        $this->assertSame([[1, 2]], $this->bounds($candidate));
    }

    public function testDeletesAnElementThatTheMinimumSizeOfACollectionRequiresTogetherWithTheChoiceThatAddedTheFirstOtherElement(): void
    {
        $candidate = Candidate::from(Sequences::ofBooleans([true, false, true, false], 2));
        $spans     = array_values($candidate->spans());
        $first     = $spans[1] ?? null;

        // the list, the two elements that the minimum size requires and
        // their booleans, the two elements that a choice added and their
        // booleans, and the span that ends the list
        $this->assertSame([1, 0, 1, 1, 1, 0, 0], $candidate->choices());
        $this->assertSame([[0, 7], [0, 1], [0, 1], [1, 2], [1, 2], [2, 4], [3, 4], [4, 6], [5, 6], [6, 7]], $this->bounds($candidate));
        $this->assertNotNull($first);
        $this->assertTrue($candidate->deleteRequiredElement($first));

        // the first element that a choice added is the second of the two
        // elements that the minimum size requires
        $this->assertSame([0, 1, 1, 0, 0], $candidate->choices());
        $this->assertSame([[0, 5], [0, 1], [0, 1], [1, 2], [1, 2], [2, 4], [3, 4], [4, 5]], $this->bounds($candidate));
        $this->assertSame(
            [false, true, false, true, false, false, false, false],
            array_map(static fn (array $span): bool => $candidate->isRequired($span), array_values($candidate->spans())),
        );
        $this->assertSame(
            [false, false, false, false, false, true, false, true],
            array_map(static fn (array $span): bool => $candidate->isDecided($span), array_values($candidate->spans())),
        );
    }

    public function testDoesNotDeleteAnElementThatTheMinimumSizeOfACollectionRequiresWhenNoChoiceAddedAnElementToTheCollection(): void
    {
        // a list that ends after the elements that its minimum size requires
        $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2));
        $first     = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($first);
        $this->assertFalse($candidate->deleteRequiredElement($first));
        $this->assertSame([1, 0, 0], $candidate->choices());

        // a list that has its maximum size
        $candidate = Candidate::from(Sequences::ofBooleans([true, false], 2, 2));
        $first     = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($first);
        $this->assertFalse($candidate->deleteRequiredElement($first));
        $this->assertSame([1, 0], $candidate->choices());

        // a list whose choice that added the second element was set to 0
        $candidate = Candidate::from(Sequences::ofBooleans([true, true, true], 1));
        $first     = array_values($candidate->spans())[1] ?? null;

        $this->assertNotNull($first);
        $this->assertSame([1, 1, 1, 1, 1, 0], $candidate->choices());

        $candidate->set(1, 0);

        $this->assertFalse($candidate->deleteRequiredElement($first));
        $this->assertSame([1, 0, 1, 1, 1, 0], $candidate->choices());
    }

    public function testInsertingChoicesMovesSpansThatStartAtThePositionAndExtendsSpansThatContainIt(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('ab'));

        $candidate->insert(2, [1, 0x7A], [1, 0xFF], Label::of('element', new Bytes(0, 100)->label()), 0, SpanKind::Element);

        $this->assertSame([1, 0x61, 1, 0x7A, 1, 0x62, 0], $candidate->choices());
        $this->assertSame([[0, 7], [0, 2], [4, 6], [6, 7], [2, 4]], $this->bounds($candidate));
        $this->assertSame(0xFF, $candidate->maximumAt(3));
    }

    public function testInsertedChoicesHaveNoKnownMaximum(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([5], [], [9]));

        $candidate->insert(1, [7, 8]);
        $candidate->insert(0, []);

        $this->assertSame([5, 7, 8], $candidate->choices());
        $this->assertSame(9, $candidate->maximumAt(0));
        $this->assertNull($candidate->maximumAt(1));
    }

    public function testEmptySpansAreIgnoredAndMoveWithWhatFollowsThem(): void
    {
        $candidate = Candidate::from(new ChoiceSequence([1, 2, 3], [new Span(1, 2, 'a', null)], []));

        $candidate->delete(1, 1);

        $this->assertSame([], $this->bounds($candidate));

        $candidate->insert(0, [9]);
        $candidate->insert(2, [8], null, 'b', null);

        $this->assertSame([9, 1, 8, 3], $candidate->choices());
        $this->assertSame([[2, 3]], $this->bounds($candidate));
    }

    public function testReplacingARangeAdjustsTheSpans(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('abc'));

        // replaces the second byte with two bytes
        $candidate->replace(2, 4, [1, 0x78, 1, 0x79], [1, 0xFF, 1, 0xFF]);

        $this->assertSame([1, 0x61, 1, 0x78, 1, 0x79, 1, 0x63, 0], $candidate->choices());
        $this->assertSame([[0, 9], [0, 2], [2, 6], [6, 8], [8, 9]], $this->bounds($candidate));
    }

    public function testReplacingARangeEmptiesTheSpansWithinItAndShrinksTheSpansThatCrossIt(): void
    {
        $candidate = Candidate::from(
            new ChoiceSequence(
                [1, 2, 3, 4, 5, 6],
                [new Span(0, 3, 'a', null), new Span(2, 4, 'b', null), new Span(3, 6, 'c', null)],
                [],
            ),
        );

        $candidate->replace(2, 4, [9], [null]);

        $this->assertSame([1, 2, 9, 5, 6], $candidate->choices());
        $this->assertSame([[0, 2], [2, 3], [2, 5]], $this->bounds($candidate));
    }

    public function testCutsOffTheTail(): void
    {
        $candidate = Candidate::from(Sequences::ofBytes('abc'));

        $candidate->truncate(3);
        $candidate->truncate(10);

        $this->assertSame([1, 0x61, 1], $candidate->choices());
        $this->assertSame([[0, 3], [0, 2], [2, 3]], $this->bounds($candidate));
    }

    public function testSlicesChoicesWithTheirMaxima(): void
    {
        $this->assertSame(
            ['choices' => [1, 0x62], 'maxima' => [1, 0xFF]],
            Candidate::from(Sequences::ofBytes('ab'))->slice(2, 4),
        );
    }

    /**
     * @return list<array{0: non-negative-int, 1: non-negative-int}>
     */
    private function bounds(Candidate $candidate): array
    {
        $bounds = [];

        foreach (array_values($candidate->spans()) as $span) {
            $bounds[] = [$span['start'], $span['end']];
        }

        return $bounds;
    }
}
