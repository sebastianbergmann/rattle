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

#[CoversClass(WorkerConfiguration::class)]
#[UsesClass(ArgumentsBuilderException::class)]
#[Small]
final class WorkerConfigurationTest extends TestCase
{
    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function invalidArguments(): array
    {
        return [
            'no arguments'                                   => [[]],
            'target without a value'                         => [['--target']],
            'unknown option'                                 => [['--target', 'target.php', '--unknown', 'value']],
            'timeout of zero'                                => [['--target', 'target.php', '--timeout', '0']],
            'maximum number of choices that is not a number' => [['--target', 'target.php', '--max-choices', 'many']],
        ];
    }

    public function testCanBePassedAsArguments(): void
    {
        $configuration = new WorkerConfiguration('target.php', 'bootstrap.php', ['src', 'lib'], 2, 100, '256M', true, 50);
        $parsed        = WorkerConfiguration::fromArguments($configuration->asArguments());

        $this->assertEquals($configuration, $parsed);
        $this->assertSame('target.php', $parsed->target());
        $this->assertSame('bootstrap.php', $parsed->bootstrap());
        $this->assertSame(['src', 'lib'], $parsed->sources());
        $this->assertSame(2, $parsed->timeout());
        $this->assertSame(100, $parsed->maximumNumberOfChoices());
        $this->assertSame('256M', $parsed->memoryLimit());
        $this->assertTrue($parsed->ignoreDeprecations());
        $this->assertSame(50, $parsed->numberOfExecutions());
    }

    public function testHasDefaults(): void
    {
        $configuration = WorkerConfiguration::fromArguments(['--target', 'target.php']);

        $this->assertNull($configuration->bootstrap());
        $this->assertSame([], $configuration->sources());
        $this->assertSame(1, $configuration->timeout());
        $this->assertNull($configuration->memoryLimit());
        $this->assertFalse($configuration->ignoreDeprecations());
        $this->assertSame(10000, $configuration->numberOfExecutions());
        $this->assertEquals($configuration, WorkerConfiguration::fromArguments($configuration->asArguments()));
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArguments(array $arguments): void
    {
        $this->expectException(ArgumentsBuilderException::class);

        WorkerConfiguration::fromArguments($arguments);
    }
}
