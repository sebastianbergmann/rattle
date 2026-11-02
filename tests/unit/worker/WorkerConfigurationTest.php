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
#[UsesClass(PhpunitTestSuite::class)]
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
            'empty filter'                                   => [['--phpunit', '--phpunit-filter', '']],
        ];
    }

    public function testCanBePassedAsArguments(): void
    {
        $configuration = new WorkerConfiguration('target.php', 'bootstrap.php', ['src', 'lib'], 2, 100, '256M', true, 50, null, true);
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
        $this->assertTrue($parsed->opcache());
    }

    public function testCanBePassedAsArgumentsForAPropertyOfAPhpunitTestSuite(): void
    {
        $configuration = new WorkerConfiguration('Vendor\ParserTest::testRoundTrip', null, [], 1, 100, null, false, 50, new PhpunitTestSuite('phpunit.xml', 'Parser'));
        $parsed        = WorkerConfiguration::fromArguments($configuration->asArguments());

        $phpunit = $parsed->phpunit();

        $this->assertEquals($configuration, $parsed);
        $this->assertSame('Vendor\ParserTest::testRoundTrip', $parsed->target());
        $this->assertNotNull($phpunit);
        $this->assertSame('phpunit.xml', $phpunit->configurationFile());
        $this->assertSame('Parser', $phpunit->filter());
    }

    public function testListsThePropertiesOfAPhpunitTestSuiteWithoutATarget(): void
    {
        $configuration = new WorkerConfiguration('Vendor\ParserTest::testRoundTrip', null, [], 1, 100, null, false, 50, new PhpunitTestSuite(null, null));
        $parsed        = WorkerConfiguration::fromArguments($configuration->withTarget('')->asArguments());

        $this->assertSame('', $parsed->target());
        $this->assertNotNull($parsed->phpunit());
        $this->assertNull($parsed->phpunit()->configurationFile());
        $this->assertNull($parsed->phpunit()->filter());
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
        $this->assertFalse($configuration->opcache());
        $this->assertEquals($configuration, WorkerConfiguration::fromArguments($configuration->asArguments()));
    }

    public function testTurnsTheFileCacheOfOpcacheOffForTheWorkerProcess(): void
    {
        $configuration = new WorkerConfiguration('target.php', null, [], 1, 100, null, false, 50);

        $this->assertSame(['-d', 'opcache.file_cache=', '-d', 'opcache.file_cache_only=0'], $configuration->phpSettings());
    }

    public function testTurnsOpcacheAndItsJitOnForTheWorkerProcessWhenItIsAskedTo(): void
    {
        $configuration = new WorkerConfiguration('target.php', null, [], 1, 100, null, false, 50, null, true);

        $this->assertSame(
            [
                '-d', 'opcache.file_cache=',
                '-d', 'opcache.file_cache_only=0',
                '-d', 'opcache.enable=1',
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.jit=function',
                '-d', 'opcache.jit_buffer_size=128M',
            ],
            $configuration->phpSettings(),
        );
        $this->assertTrue($configuration->withTarget('other.php')->opcache());
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
