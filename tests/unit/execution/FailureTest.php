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
use RuntimeException;
use SebastianBergmann\ChoiceSequence\FailureSignature;

#[CoversClass(Failure::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(ProtocolException::class)]
#[Small]
final class FailureTest extends TestCase
{
    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidData(): array
    {
        return [
            'unknown kind'                             => [['kind' => 'other', 'message' => '', 'trace' => '']],
            'throwable without a class and a location' => [['kind' => 'throwable', 'message' => '', 'trace' => '']],
            'empty description'                        => [['kind' => 'description', 'description' => '', 'message' => '', 'trace' => '']],
            'timeout without a message'                => [['kind' => 'timeout', 'trace' => '']],
        ];
    }

    public function testAThrowableIsIdentifiedByItsClassAndLocation(): void
    {
        $failure = Failure::throwable(RuntimeException::class, '/path/to/file.php:12', 'message', 'trace');

        $this->assertSame(Failure::THROWABLE, $failure->kind());
        $this->assertSame('message', $failure->message());
        $this->assertSame('trace', $failure->trace());
        $this->assertSame(RuntimeException::class, $failure->className());
        $this->assertSame('/path/to/file.php:12', $failure->location());
        $this->assertSame('RuntimeException at /path/to/file.php:12', $failure->describe());
        $this->assertTrue(FailureSignature::forThrowable(RuntimeException::class, '/path/to/file.php:12')->isSameAs($failure->signature()));
        $this->assertFalse($failure->isTimeout());
        $this->assertFalse($failure->isExit());
    }

    public function testATimeoutIsAlwaysTheSame(): void
    {
        $failure = Failure::timeout('message', 'trace');

        $this->assertTrue($failure->isTimeout());
        $this->assertTrue(FailureSignature::forTimeout()->isSameAs($failure->signature()));
        $this->assertSame('exceeding the time limit', $failure->describe());
    }

    public function testAnIssueIsIdentifiedByItsDescription(): void
    {
        $failure = Failure::description('Warning at /path/to/file.php:3', 'message', 'trace');

        $this->assertTrue(FailureSignature::forDescription('Warning at /path/to/file.php:3')->isSameAs($failure->signature()));
        $this->assertNull($failure->className());
        $this->assertNull($failure->location());
    }

    public function testAProcessThatExitedIsDescribedByItsExitStatus(): void
    {
        $failure = Failure::exit();

        $this->assertTrue($failure->isExit());
        $this->assertTrue(FailureSignature::forDescription('Process exited')->isSameAs($failure->signature()));
        $this->assertSame('Process exited with status 3', $failure->exited(3)->describe());
    }

    public function testAProcessThatEndedIsDescribedBySignalOrExitStatus(): void
    {
        $this->assertSame('Process terminated by signal 11', Failure::processEnded(11, null)->describe());
        $this->assertSame('Process exited with status 255', Failure::processEnded(null, 255)->describe());
    }

    public function testCanBeConvertedToAndFromAnArray(): void
    {
        foreach ([
            Failure::throwable(RuntimeException::class, 'file.php:1', 'm', 't'),
            Failure::timeout('m', 't'),
            Failure::description('Fatal error at file.php:2', 'm', 't'),
            Failure::exit(),
        ] as $failure) {
            $this->assertEquals($failure, Failure::fromArray($failure->asArray()));
        }
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(array $data): void
    {
        $this->expectException(ProtocolException::class);

        Failure::fromArray($data);
    }
}
