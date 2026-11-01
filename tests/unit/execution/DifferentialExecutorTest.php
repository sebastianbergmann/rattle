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

use function basename;
use function dirname;
use function sprintf;
use Closure;
use DomainException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\Edges;

#[CoversClass(DifferentialExecutor::class)]
#[CoversClass(DifferenceFinder::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(Failure::class)]
#[UsesClass(InProcessExecutor::class)]
#[UsesClass(Input::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(PhpBinary::class)]
#[UsesClass(Result::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Target::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(ValueExporter::class)]
#[UsesClass(Edges::class)]
#[Medium]
final class DifferentialExecutorTest extends TestCase
{
    private const string FILE = 'tests/unit/execution/DifferentialExecutorTest.php';

    public function testAnInputWithTheSameOutcomeOnAllVersionsPasses(): void
    {
        $executor = $this->executor(
            static function (string $bytes): void
            {
            },
            static function (string $bytes): void
            {
                throw new DomainException('expected');
            },
        );

        $result = $executor->execute(Job::replay(3, BytesEncoding::choicesFor('a', 16)));

        $this->assertSame(3, $result->id());
        $this->assertSame(Outcome::Passed, $result->outcome());
        $this->assertSame(BytesEncoding::choicesFor('a', 16), $result->sequence()->choices());
        $this->assertSame(['PHP 8.5', 'PHP 8.6'], $executor->labels());
        $this->assertSame('target', $executor->target()->name());
    }

    public function testAnInputThatHasAnotherOutcomeOnOneVersionIsADifference(): void
    {
        $line     = __LINE__ + 8;
        $executor = $this->executor(
            static function (string $bytes): void
            {
            },
            static function (string $bytes): void
            {
                if ($bytes !== '') {
                    throw new LogicException('fails on 8.6');
                }
            },
        );

        $executor->submit([Job::replay(1, BytesEncoding::choicesFor('', 16)), Job::replay(2, BytesEncoding::choicesFor('a', 16))]);

        $this->assertSame(0, $executor->capacity());

        $results   = $executor->collect();
        $same      = $results[0] ?? null;
        $different = $results[1] ?? null;

        $this->assertSame(1, $executor->capacity());
        $this->assertSame(Outcome::Passed, $same?->outcome());
        $this->assertSame(Outcome::Failed, $different?->outcome());
        $this->assertSame(
            sprintf('Difference between PHP versions: PHP 8.5: passes; PHP 8.6: %s at %s:%d', LogicException::class, self::FILE, $line),
            $different->failure()?->describe(),
        );
        $this->assertSame('PHP 8.6: fails on 8.6', $different->failure()->message());
        $this->assertStringStartsWith('PHP 8.6:' . "\n" . self::FILE . ':' . $line, $different->failure()->trace());
    }

    public function testRejectingAnInputIsAnOutcomeOfItsOwn(): void
    {
        $executor = $this->executor(
            static function (string $bytes): void
            {
            },
            static function (string $bytes): void
            {
                throw new InputRejectedException;
            },
        );

        $this->assertSame(
            'Difference between PHP versions: PHP 8.5: passes; PHP 8.6: rejects the input',
            $executor->execute(Job::replay(1, BytesEncoding::choicesFor('a', 16)))->failure()?->describe(),
        );
    }

    public function testTellsVersionsOfTheSameMajorAndMinorVersionApartByTheNamesOfTheirBinaries(): void
    {
        $wrapper = PhpBinary::at(dirname(__DIR__, 2) . '/fixtures/php/php-precision-5');

        $this->assertSame(
            [PhpBinary::current()->label(), PhpBinary::current()->label() . ' (' . basename($wrapper->path()) . ')'],
            DifferentialExecutor::labelsOf([PhpBinary::current(), $wrapper]),
        );
    }

    public function testADifferenceFinderPassesTheDifferencesOfInputsToAFindingHandler(): void
    {
        $handler = new class implements FindingHandler
        {
            /**
             * @var list<Result>
             */
            public array $handled = [];

            public function handle(Result $result, Job $job): bool
            {
                $this->handled[] = $result;

                return true;
            }
        };

        $finder = new DifferenceFinder(
            $this->executor(
                static function (string $bytes): void
                {
                },
                static function (string $bytes): void
                {
                    if ($bytes === 'b') {
                        throw new LogicException;
                    }
                },
            ),
            $handler,
        );

        $this->assertNull($finder->check($this->resultFor('a'), Job::replay(1, [])));
        $this->assertTrue($finder->check($this->resultFor('b'), Job::replay(2, [])));
        $this->assertCount(1, $handler->handled);
    }

    private function resultFor(string $bytes): Result
    {
        return (new InProcessExecutor(new Runner(Target::bytes('target', static fn (string $bytes) => null, 16), 100, 0, false)))->execute(Job::replay(1, BytesEncoding::choicesFor($bytes, 16)));
    }

    /**
     * @param Closure(string): void $first
     * @param Closure(string): void $second
     */
    private function executor(Closure $first, Closure $second): DifferentialExecutor
    {
        return new DifferentialExecutor(
            [
                'PHP 8.5' => new InProcessExecutor(new Runner(Target::bytes('target', $first, 16)->expect(DomainException::class), 100, 0, false)),
                'PHP 8.6' => new InProcessExecutor(new Runner(Target::bytes('target', $second, 16)->expect(DomainException::class), 100, 0, false)),
            ],
        );
    }
}
