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
            'empty span'                       => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 0, 'a', null]], 'maxima' => []], 'features' => []]],
            'span without a label'             => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, '', null]], 'maxima' => []], 'features' => []]],
            'negative parent of a span'        => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, 'a', -1]], 'maxima' => []], 'features' => []]],
            'unknown kind of span'             => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [[0, 1, 'a', null, 'Other']], 'maxima' => []], 'features' => []]],
            'negative maximum'                 => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [], 'maxima' => [-1]], 'features' => []]],
            'feature that is not an integer'   => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [1], 'spans' => [], 'maxima' => []], 'features' => ['a']]],
            'drawn value that is not a string' => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'drawnValues' => [1]]],
            'token that is not base64-encoded' => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'tokens' => ['not base64!']]],
            'empty token'                      => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'tokens' => ['']]],
            'token that is not a string'       => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'tokens' => [1]]],
            'negative number of files'         => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'instrumentedFiles' => -1]],
            'number of files that is not int'  => [['id' => 1, 'outcome' => 'passed', 'sequence' => ['choices' => [], 'spans' => [], 'maxima' => []], 'features' => [], 'instrumentedFiles' => '1']],
            'empty path'                       => [['id' => 1, 'outcome' => 'passed', 'features' => [], 'path' => '']],
            'features of a path without it'    => [['id' => 1, 'outcome' => 'passed', 'features' => [], 'pathFeatures' => [1]]],
            'feature of a path that is no int' => [['id' => 1, 'outcome' => 'passed', 'features' => [], 'path' => 'abc', 'pathFeatures' => ['a']]],
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
        $this->assertSame(["'a'"], $passed->drawnValues());
        $this->assertNull($failed->drawnValues());
    }

    public function testHasTheTokensThatWereFoundWhileTheInputWasExecuted(): void
    {
        $result = new Result(1, Outcome::Passed, null, new ChoiceSequence([1]), [], null);

        $this->assertSame([], $result->tokens());
        $this->assertArrayNotHasKey('tokens', $result->asArray());

        $result = $result->withTokens(["\x89PNG\r\n", 'GET']);

        $this->assertSame(["\x89PNG\r\n", 'GET'], Result::fromArray($result->asArray())->tokens());
        $this->assertSame(['GET'], $result->withTokens(['GET'])->withFailure(Failure::exit())->tokens());
    }

    public function testHasTheNumberOfFilesThatWereInstrumentedSinceThePreviousResult(): void
    {
        $result = new Result(1, Outcome::Passed, null, new ChoiceSequence([1]), [], null);

        $this->assertSame(0, $result->instrumentedFiles());
        $this->assertArrayNotHasKey('instrumentedFiles', $result->asArray());

        $result = $result->withInstrumentedFiles(3);

        $this->assertSame(3, Result::fromArray($result->asArray())->instrumentedFiles());
        $this->assertSame(3, $result->withTokens(['GET'])->withFailure(Failure::exit())->instrumentedFiles());
    }

    public function testIdentifiesThePathThatTheInputTookWithItsFeaturesWhenTheyWereNotReportedBefore(): void
    {
        $result = new Result(1, Outcome::Passed, null, null, [], null);

        $this->assertNull($result->path());
        $this->assertNull($result->pathFeatures());
        $this->assertArrayNotHasKey('path', $result->asArray());
        $this->assertArrayNotHasKey('pathFeatures', $result->asArray());

        $new      = $result->withPath('abc', [1 << 56 | 7, 3]);
        $reported = $result->withPath('def', null);
        $empty    = $result->withPath('ghi', []);

        $this->assertEquals($new, Result::fromArray($new->asArray()));
        $this->assertEquals($reported, Result::fromArray($reported->asArray()));
        $this->assertEquals($empty, Result::fromArray($empty->asArray()));
        $this->assertSame('abc', $new->path());
        $this->assertSame([1 << 56 | 7, 3], $new->pathFeatures());
        $this->assertNull($reported->pathFeatures());
        $this->assertSame([], $empty->pathFeatures());
        $this->assertSame([1 << 56 | 7, 3], $new->withTokens(['GET'])->withInstrumentedFiles(2)->withFailure(Failure::exit())->pathFeatures());
        $this->assertSame('abc', $new->withTokens(['GET'])->withInstrumentedFiles(2)->withFailure(Failure::exit())->path());
    }

    public function testTellsWhetherTheInputProducedTheFeaturesThatItRequired(): void
    {
        $produced = new Result(1, Outcome::Passed, null, new ChoiceSequence([1]), [], null, [], true);
        $other    = new Result(1, Outcome::Passed, null, new ChoiceSequence([1]), [], null);

        $this->assertTrue($produced->producedRequiredFeatures());
        $this->assertFalse($other->producedRequiredFeatures());
        $this->assertTrue(Result::fromArray($produced->asArray())->producedRequiredFeatures());
        $this->assertArrayNotHasKey('producedRequiredFeatures', $other->asArray());
        $this->assertTrue($produced->withTokens(['GET'])->producedRequiredFeatures());
    }

    public function testTheResultOfATerseInputCanBeWithoutItsSequence(): void
    {
        $result = Result::fromArray((new Result(1, Outcome::Passed, null, null, [], null))->asArray());

        $this->assertFalse($result->hasSequence());
        $this->assertArrayNotHasKey('sequence', $result->asArray());
        $this->assertTrue((new Result(1, Outcome::Passed, null, new ChoiceSequence([1]), [], null))->hasSequence());
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
