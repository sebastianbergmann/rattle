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
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Span;
use SebastianBergmann\ChoiceSequence\SpanKind;

#[CoversClass(Result::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Job::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(ProtocolException::class)]
#[Small]
final class ResultTest extends TestCase
{
    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidData(): array
    {
        return [
            'id missing'                       => [['outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => []]],
            'unknown outcome'                  => [['id' => 1, 'outcome' => 'other', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => []]],
            'sequence missing'                 => [['id' => 1, 'outcome' => 'passed', 'features' => []]],
            'empty span'                       => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 0, 'a', null]], 'maxima' => []], 'features' => []]],
            'span without a label'             => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, '', null]], 'maxima' => []], 'features' => []]],
            'negative parent of a span'        => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, 'a', -1]], 'maxima' => []], 'features' => []]],
            'unknown kind of span'             => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, 'a', null, 'Other']], 'maxima' => []], 'features' => []]],
            'negative maximum'                 => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [], 'maxima' => [-1]], 'features' => []]],
            'feature that is not an integer'   => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [], 'maxima' => []], 'features' => ['a']]],
            'drawn value that is not a string' => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'drawnValues' => [1]]],
        ];
    }

    public function testCanBeConvertedToAndFromAnArray(): void
    {
        $sequence = new ChoiceSequence(
            [1, 97, 0],
            [new Span(0, 3, 'bytes', null, SpanKind::Bytes), new Span(0, 2, 'byte', 0, SpanKind::Element), new Span(2, 3, 'byte', 0, SpanKind::Element), new Span(1, 2, 'value', 1)],
            [1, 255, 1],
        );

        $passed = new Result(1, Outcome::Passed, null, $sequence, [1, 1 << 56 | 7], ["'a'"]);
        $failed = new Result(2, Outcome::Failed, Failure::timeout('m', 't'), new ChoiceSequence([3]), [], null);

        $this->assertEquals($passed, Result::fromArray($passed->asArray()));
        $this->assertEquals($failed, Result::fromArray($failed->asArray()));
        $this->assertTrue($failed->isFinding());
        $this->assertFalse($passed->isFinding());
    }

    public function testTheInputThatWasSentStandsInForTheConsumedSequenceWhenTheProcessEnded(): void
    {
        $result = Result::processEnded(Job::replay(9, [4, 5]), Failure::processEnded(9, null));

        $this->assertSame(9, $result->id());
        $this->assertSame(Outcome::Failed, $result->outcome());
        $this->assertSame([4, 5], $result->sequence()->choices());
        $this->assertSame([], $result->sequence()->spans());
        $this->assertSame([], $result->features());
    }

    public function testTheFailureCanBeReplaced(): void
    {
        $result = new Result(1, Outcome::Failed, Failure::exit(), new ChoiceSequence([1]), [], null);

        $this->assertSame('Process exited with status 2', $result->withFailure(Failure::exit()->exited(2))->failure()?->describe());
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(array $data): void
    {
        $this->expectException(ProtocolException::class);

        Result::fromArray($data);
    }
}
