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

use function realpath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\Rattle\Instrumentation\SourceResolver;

#[CoversClass(Setup::class)]
#[CoversClass(ProcessorCount::class)]
#[CoversClass(ConfigurationException::class)]
#[UsesClass(Arguments::class)]
#[UsesClass(ArgumentsBuilder::class)]
#[UsesClass(SourceResolver::class)]
#[UsesClass(WorkerConfiguration::class)]
#[Small]
final class SetupTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/targets';

    public function testConfiguresTheWorkers(): void
    {
        $configuration = (new Setup)->workerConfiguration(
            (new ArgumentsBuilder)->build(
                ['rattle', 'fuzz', self::FIXTURES . '/exit/target.php', '--bootstrap', self::FIXTURES . '/bootstrap.php', '--source', self::FIXTURES . '/exit/src', '--timeout', '2', '--memory-limit', '64M', '--ignore-deprecations', '--worker-executions', '7'],
            ),
        );

        $this->assertSame(self::FIXTURES . '/exit/target.php', $configuration->target());
        $this->assertSame(self::FIXTURES . '/bootstrap.php', $configuration->bootstrap());
        $this->assertSame([realpath(self::FIXTURES . '/exit/src')], $configuration->sources());
        $this->assertSame(2, $configuration->timeout());
        $this->assertSame('64M', $configuration->memoryLimit());
        $this->assertTrue($configuration->ignoreDeprecations());
        $this->assertSame(7, $configuration->numberOfExecutions());
    }

    public function testUsesTheAutoloaderOfComposerAsTheDefaultBootstrapFile(): void
    {
        $configuration = (new Setup)->workerConfiguration((new ArgumentsBuilder)->build(['rattle', 'fuzz', self::FIXTURES . '/exit/target.php']));

        $this->assertSame(realpath(__DIR__ . '/../../../vendor/autoload.php'), realpath((string) $configuration->bootstrap()));
        $this->assertSame([realpath(__DIR__ . '/../../../src')], $configuration->sources());
    }

    public function testRejectsATargetFileThatDoesNotExist(): void
    {
        $this->expectException(ConfigurationException::class);

        (new Setup)->workerConfiguration((new ArgumentsBuilder)->build(['rattle', 'fuzz', '/does/not/exist.php']));
    }

    public function testRejectsABootstrapFileThatDoesNotExist(): void
    {
        $this->expectException(ConfigurationException::class);

        (new Setup)->workerConfiguration((new ArgumentsBuilder)->build(['rattle', 'fuzz', self::FIXTURES . '/exit/target.php', '--bootstrap', '/does/not/exist.php']));
    }

    public function testRejectsASourceDirectoryThatDoesNotExist(): void
    {
        $this->expectException(ConfigurationException::class);

        (new Setup)->workerConfiguration((new ArgumentsBuilder)->build(['rattle', 'fuzz', self::FIXTURES . '/exit/target.php', '--source', '/does/not/exist']));
    }

    public function testForwardsTheStandardErrorStreamsOfTheWorkersOnlyWhenVerbose(): void
    {
        $this->assertNull((new Setup)->stderr((new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php'])));
        $this->assertNotNull((new Setup)->stderr((new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php', '--verbose'])));
    }

    public function testCountsTheProcessors(): void
    {
        $this->assertGreaterThanOrEqual(1, (new ProcessorCount)->count());
    }
}
