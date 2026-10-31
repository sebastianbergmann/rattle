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

use DomainException;
use Error;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use stdClass;

#[CoversClass(Target::class)]
#[CoversClass(InvalidTargetException::class)]
#[UsesClass(Input::class)]
#[UsesClass(BytesEncoding::class)]
#[Small]
final class TargetTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty'            => [''],
            'uppercase letter' => ['Parser'],
            'space'            => ['csv parser'],
            'underscore'       => ['csv_parser'],
            'path'             => ['../parser'],
        ];
    }

    public function testRunsABytesTargetWithOneBinaryString(): void
    {
        $received = null;

        $target = Target::bytes(
            'parser',
            static function (string $bytes) use (&$received): void
            {
                $received = $bytes;
            },
            4,
        );

        $target->run(new Input(ChoiceSource::replay(new ChoiceSequence(BytesEncoding::choicesFor('abcdef', 4))), false));

        $this->assertSame('abcd', $received);
        $this->assertSame('parser', $target->name());
        $this->assertTrue($target->isBytesTarget());
        $this->assertSame(4, $target->maximumLength());
        $this->assertSame(9, $target->requiredNumberOfChoices());
    }

    public function testRunsAStructuredTargetWithAnInput(): void
    {
        $received = null;

        $target = Target::structured(
            'round-trip',
            static function (Input $input) use (&$received): void
            {
                $received = $input->int(0, 100);
            },
        );

        $target->run(new Input(ChoiceSource::replay(new ChoiceSequence([42])), false));

        $this->assertSame(42, $received);
        $this->assertFalse($target->isBytesTarget());
        $this->assertNull($target->maximumLength());
        $this->assertNull($target->requiredNumberOfChoices());
    }

    #[DataProvider('invalidNames')]
    public function testRejectsAnInvalidName(string $name): void
    {
        $this->expectException(InvalidTargetException::class);

        /** @phpstan-ignore argument.type */
        Target::structured($name, static function (Input $input): void
        {
        });
    }

    public function testRejectsANonPositiveMaximumLength(): void
    {
        $this->expectException(InvalidTargetException::class);

        $maximumLength = 0;

        /** @phpstan-ignore argument.type */
        Target::bytes('parser', static fn (string $bytes) => null, $maximumLength);
    }

    public function testThrowablesOfExpectedClassesAndTheirSubclassesAreExpected(): void
    {
        $target = $this->structuredTarget()->expect(LogicException::class)->expect(RuntimeException::class);

        $this->assertSame([LogicException::class, RuntimeException::class], $target->expected());
        $this->assertTrue($target->isExpected(new LogicException));
        $this->assertTrue($target->isExpected(new DomainException));
        $this->assertFalse($target->isExpected(new Error));
        $this->assertSame([], $this->structuredTarget()->expected());
    }

    public function testOnlyThrowablesCanBeExpected(): void
    {
        $this->expectException(InvalidTargetException::class);

        /** @phpstan-ignore argument.type */
        $this->structuredTarget()->expect(stdClass::class);
    }

    private function structuredTarget(): Target
    {
        return Target::structured('target', static function (Input $input): void
        {
        });
    }
}
