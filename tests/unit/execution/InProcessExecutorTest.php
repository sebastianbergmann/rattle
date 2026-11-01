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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(InProcessExecutor::class)]
#[CoversClass(TargetDescription::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(NewFeatures::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[UsesClass(Input::class)]
#[UsesClass(Target::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Features::class)]
#[Small]
final class InProcessExecutorTest extends TestCase
{
    public function testExecutesBatchesOfInputsInOrder(): void
    {
        $executor = $this->executor();

        $this->assertSame(1, $executor->capacity());
        $this->assertSame([], $executor->collect());

        $executor->submit([Job::replay(1, [1, 0x61, 0]), Job::replay(2, [0]), Job::replay(3, [1, 0x21, 0])]);

        $this->assertSame(0, $executor->capacity());

        $results = $executor->collect();

        $this->assertSame([1, 2, 3], array_map(static fn (Result $result): int => $result->id(), $results));
        $this->assertSame(
            [Outcome::Passed, Outcome::Passed, Outcome::Failed],
            array_map(static fn (Result $result): Outcome => $result->outcome(), $results),
        );
        $this->assertSame(1, $executor->capacity());

        $executor->shutdown();
    }

    public function testExecutesOneInput(): void
    {
        $this->assertSame(Outcome::Failed, $this->executor()->execute(Job::replay(1, [1, 0x21, 0]))->outcome());
    }

    public function testDescribesTheTarget(): void
    {
        $description = $this->executor()->target();

        $this->assertSame('exclamation-mark', $description->name());
        $this->assertTrue($description->isBytesTarget());
        $this->assertSame(10, $description->maximumLength());
        $this->assertSame(100, $description->maximumNumberOfChoices());
    }

    private function executor(): InProcessExecutor
    {
        return new InProcessExecutor(
            new Runner(
                Target::bytes(
                    'exclamation-mark',
                    static function (string $bytes): void
                    {
                        if ($bytes === '!') {
                            throw new RuntimeException;
                        }
                    },
                    10,
                ),
                100,
                0,
                false,
            ),
        );
    }
}
