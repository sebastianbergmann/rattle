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
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\UniformDistribution;

#[CoversClass(Job::class)]
#[CoversClass(ProtocolData::class)]
#[CoversClass(ProtocolException::class)]
#[Small]
final class JobTest extends TestCase
{
    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidData(): array
    {
        return [
            'id missing'                              => [['drawnValues' => false, 'choices' => []]],
            'negative id'                             => [['id' => -1, 'drawnValues' => false, 'choices' => []]],
            'drawnValues missing'                     => [['id' => 1, 'choices' => []]],
            'choices missing'                         => [['id' => 1, 'drawnValues' => false]],
            'negative choice'                         => [['id' => 1, 'drawnValues' => false, 'choices' => [-1]]],
            'choices that are not a list'             => [['id' => 1, 'drawnValues' => false, 'choices' => ['a' => 1]]],
            'choices that are not an array'           => [['id' => 1, 'drawnValues' => false, 'choices' => 'abc']],
            'allFeatures that is not a bool'          => [['id' => 1, 'drawnValues' => false, 'allFeatures' => 1, 'choices' => []]],
            'required feature that is not an integer' => [['id' => 1, 'drawnValues' => false, 'requiredFeatures' => ['a'], 'choices' => []]],
            'empty target'                            => [['id' => 1, 'drawnValues' => false, 'target' => '', 'choices' => []]],
        ];
    }

    public function testReplaysAChoiceSequence(): void
    {
        $job = Job::replay(3, [1, 2], true);

        $this->assertSame(3, $job->id());
        $this->assertTrue($job->isReplay());
        $this->assertSame([1, 2], $job->choices());
        $this->assertNull($job->seed());
        $this->assertTrue($job->reportDrawnValues());
        $this->assertSame([1, 2], $job->sequence()->choices());
        $this->assertSame(2, $job->source(10)->draw(5, new UniformDistribution) + 1);
    }

    public function testGeneratesChoicesFromASeed(): void
    {
        $job = Job::generate(4, 1234);

        $this->assertFalse($job->isReplay());
        $this->assertNull($job->choices());
        $this->assertSame(1234, $job->seed());
        $this->assertFalse($job->reportDrawnValues());
        $this->assertSame([], $job->sequence()->choices());
        $this->assertSame(
            $job->source(10)->draw(1000, new UniformDistribution),
            Job::generate(5, 1234)->source(10)->draw(1000, new UniformDistribution),
        );
    }

    public function testCanAskForAllTheFeaturesThatItProduced(): void
    {
        $job = Job::replay(3, [1, 2], true);

        $this->assertFalse($job->reportAllFeatures());
        $this->assertTrue($job->withAllFeatures()->reportAllFeatures());
        $this->assertSame([1, 2], $job->withAllFeatures()->choices());
        $this->assertTrue($job->withAllFeatures()->reportDrawnValues());
    }

    public function testCanBeConvertedToAndFromAnArray(): void
    {
        $replay   = Job::replay(1, [0, 7], true);
        $generate = Job::generate(2, -5);

        $this->assertEquals($replay, Job::fromArray($replay->asArray()));
        $this->assertEquals($generate, Job::fromArray($generate->asArray()));
        $this->assertEquals($replay->withAllFeatures(), Job::fromArray($replay->withAllFeatures()->asArray()));
        $this->assertEquals($generate->withAllFeatures(), Job::fromArray($generate->withAllFeatures()->asArray()));
        $this->assertArrayNotHasKey('allFeatures', $replay->asArray());
        $this->assertEquals($replay->withRequiredFeatures([5, 1 << 56 | 6]), Job::fromArray($replay->withRequiredFeatures([5, 1 << 56 | 6])->asArray()));
        $this->assertArrayNotHasKey('requiredFeatures', $replay->asArray());
    }

    public function testCanNameTheProperty(): void
    {
        $job = Job::replay(3, [1, 2], true)->withRequiredFeatures([5])->withAllFeatures();

        $this->assertNull($job->target());
        $this->assertSame('Vendor\\ParserTest::testRoundTrip', $job->withTarget('Vendor\\ParserTest::testRoundTrip')->target());
        $this->assertSame([5], $job->withTarget('Vendor\\ParserTest::testRoundTrip')->requiredFeatures());
        $this->assertTrue($job->withTarget('Vendor\\ParserTest::testRoundTrip')->reportAllFeatures());
        $this->assertEquals($job->withTarget('Vendor\\ParserTest::testRoundTrip'), Job::fromArray($job->withTarget('Vendor\\ParserTest::testRoundTrip')->asArray()));
        $this->assertArrayNotHasKey('target', $job->asArray());
    }

    public function testCanRequireFeatures(): void
    {
        $job = Job::replay(3, [1, 2]);

        $this->assertSame([], $job->requiredFeatures());
        $this->assertSame([5, 6], $job->withRequiredFeatures([5, 6])->requiredFeatures());
        $this->assertSame([5, 6], $job->withRequiredFeatures([5, 6])->withAllFeatures()->requiredFeatures());
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(array $data): void
    {
        $this->expectException(ProtocolException::class);

        Job::fromArray($data);
    }
}
