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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\SpanKind;

#[CoversClass(PropertyExample::class)]
#[UsesClass(Failure::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(ProtocolException::class)]
#[UsesClass(WorkerException::class)]
#[Small]
final class PropertyExampleTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidData(): array
    {
        return [
            'unknown outcome'                       => [['outcome' => 'unknown'] + self::passed()],
            'failure missing'                       => [['outcome' => 'failed'] + self::passed()],
            'failure of aborted example missing'    => [['outcome' => 'aborted'] + self::passed()],
            'unknown kind of failure'               => [self::failed(['kind' => 'unknown'])],
            'signature that does not match'         => [self::failed(['signature' => 'RuntimeException at src/Other.php:1'])],
            'spans that are not a list'             => [['spans' => ['list' => ['start' => 0, 'end' => 3, 'label' => 'list', 'parent' => null, 'kind' => null]]] + self::passed()],
            'span that ends after the last choice'  => [['spans' => [['start' => 0, 'end' => 4, 'label' => 'a', 'parent' => null, 'kind' => null]]] + self::passed()],
            'span whose parent does not precede it' => [['spans' => [['start' => 0, 'end' => 1, 'label' => 'a', 'parent' => 0, 'kind' => null]]] + self::passed()],
            'unknown kind of span'                  => [['spans' => [['start' => 0, 'end' => 1, 'label' => 'a', 'parent' => null, 'kind' => 'unknown']]] + self::passed()],
            'maximum for every other choice'        => [['maxima' => [1]] + self::passed()],
            'negative maximum'                      => [['maxima' => [1, -9, 1]] + self::passed()],
            'negative choice'                       => [['choices' => [-1, 2, 0]] + self::passed()],
            'drawn values without steps'            => [['arguments' => ['a' => '1'], 'drawnValues' => [], 'steps' => null] + self::passed()],
            'drawn value with an empty label'       => [['arguments' => [], 'drawnValues' => [['label' => '', 'value' => '1']], 'steps' => []] + self::passed()],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: Outcome}>
     */
    public static function outcomesOfExamplesThatDidNotFalsifyTheProperty(): array
    {
        return [
            'passed'     => ['passed', Outcome::Passed],
            'rejected'   => ['rejected', Outcome::Rejected],
            'skipped'    => ['skipped', Outcome::Rejected],
            'incomplete' => ['incomplete', Outcome::Rejected],
        ];
    }

    public function testIsCreatedFromWhatPhpunitReturnsForAnExample(): void
    {
        $example = PropertyExample::fromArray(self::passed());

        $this->assertSame(Outcome::Passed, $example->outcome());
        $this->assertNull($example->failure());
        $this->assertSame([1, 2, 0], $example->sequence()->choices());
        $this->assertSame(1, $example->sequence()->maximumAt(0));
        $span = $example->sequence()->spans()[1] ?? null;

        $this->assertCount(2, $example->sequence()->spans());
        $this->assertNotNull($span);
        $this->assertSame(SpanKind::Element, $span->kind());
        $this->assertSame(0, $span->parent());
    }

    public function testHasTheSpansOfAStringOfUnicodeCharacters(): void
    {
        $example = PropertyExample::fromArray(['spans' => [['start' => 0, 'end' => 3, 'label' => 'string', 'parent' => null, 'kind' => 'characters'], ['start' => 0, 'end' => 2, 'label' => 'character', 'parent' => 0, 'kind' => 'element']]] + self::passed());

        $span = $example->sequence()->spans()[0] ?? null;

        $this->assertNotNull($span);
        $this->assertSame(SpanKind::Characters, $span->kind());
    }

    public function testHasTheSpansOfABinaryString(): void
    {
        $example = PropertyExample::fromArray(['spans' => [['start' => 0, 'end' => 3, 'label' => 'bytes', 'parent' => null, 'kind' => 'bytes'], ['start' => 0, 'end' => 2, 'label' => 'byte', 'parent' => 0, 'kind' => 'element']]] + self::passed());

        $span = $example->sequence()->spans()[0] ?? null;

        $this->assertNotNull($span);
        $this->assertSame(SpanKind::Bytes, $span->kind());
    }

    public function testHasTheSpansOfTheElementsThatTheMinimumSizeOfACollectionRequires(): void
    {
        $example = PropertyExample::fromArray(
            [
                'choices' => [1, 1, 0, 0],
                'maxima'  => [1, 1, 1, 1],
                'spans'   => [
                    ['start' => 0, 'end' => 4, 'label' => 'list', 'parent' => null, 'kind' => null],
                    ['start' => 0, 'end' => 1, 'label' => 'element', 'parent' => 0, 'kind' => 'requiredelement'],
                    ['start' => 1, 'end' => 3, 'label' => 'element', 'parent' => 0, 'kind' => 'element'],
                    ['start' => 3, 'end' => 4, 'label' => 'element', 'parent' => 0, 'kind' => 'element'],
                ],
            ] + self::passed(),
        );

        $kinds = [];

        foreach ($example->sequence()->spans() as $span) {
            $kinds[] = $span->kind();
        }

        $this->assertSame([null, SpanKind::RequiredElement, SpanKind::Element, SpanKind::Element], $kinds);
    }

    public function testHasTheSpansOfAValueThatAStepOfAStateMachineProducesForAPoolAndOfTheReferencesToIt(): void
    {
        $example = PropertyExample::fromArray(
            [
                'choices' => [1, 0, 0, 1, 1, 0, 1, 2, 0],
                'maxima'  => [1, 2, 0, 1, 2, 0, 1, 2, 0],
                'spans'   => [
                    ['start' => 0, 'end' => 3, 'label' => 'step', 'parent' => null, 'kind' => null],
                    ['start' => 2, 'end' => 3, 'label' => 'pool', 'parent' => 0, 'kind' => 'referable'],
                    ['start' => 3, 'end' => 6, 'label' => 'step', 'parent' => null, 'kind' => null],
                    ['start' => 5, 'end' => 6, 'label' => 'pool', 'parent' => 2, 'kind' => 'reference'],
                    ['start' => 6, 'end' => 9, 'label' => 'step', 'parent' => null, 'kind' => null],
                    ['start' => 8, 'end' => 9, 'label' => 'pool', 'parent' => 4, 'kind' => 'consumingreference'],
                ],
            ] + self::passed(),
        );

        $kinds = [];

        foreach ($example->sequence()->spans() as $span) {
            $kinds[] = $span->kind();
        }

        $this->assertSame([null, SpanKind::Referable, null, SpanKind::Reference, null, SpanKind::ConsumingReference], $kinds);
    }

    #[DataProvider('outcomesOfExamplesThatDidNotFalsifyTheProperty')]
    public function testMapsTheOutcomesOfExamplesThatDidNotFalsifyTheProperty(string $outcome, Outcome $expected): void
    {
        $this->assertSame($expected, PropertyExample::fromArray(['outcome' => $outcome] + self::passed())->outcome());
    }

    public function testHasTheFailureSignatureThatPhpunitDetermined(): void
    {
        $example = PropertyExample::fromArray(self::failed([]));
        $failure = $example->failure();

        $this->assertSame(Outcome::Failed, $example->outcome());
        $this->assertNotNull($failure);
        $this->assertSame('RuntimeException at src/Parser.php:12', $failure->describe());
        $this->assertSame('message', $failure->message());
    }

    public function testHasTheFailureOfAnExampleThatExceededItsTimeLimit(): void
    {
        $failure = PropertyExample::fromArray(self::failed(['kind' => 'timeout', 'signature' => 'timeout', 'class' => null, 'location' => null]))->failure();

        $this->assertNotNull($failure);
        $this->assertTrue($failure->isTimeout());
    }

    public function testHasTheFailureOfAnExampleThatFailedWithoutAThrowable(): void
    {
        $failure = PropertyExample::fromArray(self::failed(['kind' => 'description', 'signature' => 'Failed: message', 'class' => null, 'location' => null]))->failure();

        $this->assertNotNull($failure);
        $this->assertSame('Failed: message', $failure->describe());
    }

    public function testAnExampleThatPhpunitAbortedIsAnErrorOfTheProperty(): void
    {
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessageIsOrContains('PHPUnit aborted an example of the property without checking it: RuntimeException at src/Parser.php:12: message');

        PropertyExample::fromArray(['outcome' => 'aborted'] + self::failed([]));
    }

    public function testHasTheValuesOfTheExampleAsPhpunitPrintsThemForACounterexample(): void
    {
        $example = PropertyExample::fromArray(
            [
                'arguments'   => ['first' => '1', 'second' => "[\n    'a',\n]"],
                'drawnValues' => [['label' => 'digit', 'value' => '3'], ['label' => null, 'value' => 'true']],
                'steps'       => ['push(1)', 'pop()'],
            ] + self::passed(),
        );

        $this->assertSame(
            [
                '$first = 1;',
                "\$second = [\n    'a',\n];",
                '',
                'Draw 1 (digit): 3',
                'Draw 2: true',
                '',
                'push(1)',
                'pop()',
            ],
            $example->values(),
        );
    }

    public function testHasNoValuesWhenTheyWereNotExported(): void
    {
        $this->assertNull(PropertyExample::fromArray(self::passed())->values());
        $this->assertNull(PropertyExample::fromArray(['arguments' => null, 'drawnValues' => null, 'steps' => null] + self::passed())->values());
    }

    public function testHasNoMaximaWhenOneOfThemIsNotKnown(): void
    {
        $this->assertNull(PropertyExample::fromArray(['maxima' => [1, null, 1]] + self::passed())->sequence()->maximumAt(0));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(array $data): void
    {
        $this->expectException(ProtocolException::class);

        PropertyExample::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function passed(): array
    {
        return [
            'outcome' => 'passed',
            'failure' => null,
            'choices' => [1, 2, 0],
            'spans'   => [
                ['start' => 0, 'end' => 3, 'label' => 'list', 'parent' => null, 'kind' => null],
                ['start' => 0, 'end' => 2, 'label' => 'element', 'parent' => 0, 'kind' => 'element'],
            ],
            'maxima' => [1, 9, 1],
        ];
    }

    /**
     * @param array<string, mixed> $failure
     *
     * @return array<string, mixed>
     */
    private static function failed(array $failure): array
    {
        return [
            'outcome' => 'errored',
            'failure' => $failure + [
                'kind'        => 'throwable',
                'signature'   => 'RuntimeException at src/Parser.php:12',
                'class'       => 'RuntimeException',
                'location'    => 'src/Parser.php:12',
                'description' => 'RuntimeException at src/Parser.php:12',
                'message'     => 'message',
            ],
        ] + self::passed();
    }
}
