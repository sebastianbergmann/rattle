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

#[CoversClass(ArgumentsBuilder::class)]
#[CoversClass(Arguments::class)]
#[CoversClass(ArgumentsBuilderException::class)]
#[Small]
final class ArgumentsBuilderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function durations(): array
    {
        return [
            'seconds without a unit' => ['90', 90.0],
            'seconds'                => ['90s', 90.0],
            'minutes'                => ['30m', 1800.0],
            'days'                   => ['2d', 172800.0],
        ];
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function invalidArguments(): array
    {
        return [
            'unknown command'                               => [['rattle', 'unknown']],
            'fuzz without a target'                         => [['rattle', 'fuzz']],
            'run without an input'                          => [['rattle', 'run', 'target.php']],
            'too many arguments'                            => [['rattle', 'regress', 'target.php', 'other.php']],
            'too many arguments for run'                    => [['rattle', 'run', 'target.php', 'input', 'other']],
            'no jobs'                                       => [['rattle', 'fuzz', 'target.php', '--jobs', '0']],
            'filter without --phpunit'                      => [['rattle', 'fuzz', 'target.php', '--filter', 'Parser']],
            'target with --phpunit'                         => [['rattle', 'fuzz', '--phpunit', 'target.php']],
            'seeds with --phpunit'                          => [['rattle', 'fuzz', '--phpunit', '--seeds', 'seeds']],
            'empty configuration file'                      => [['rattle', 'fuzz', '--phpunit', '--configuration', '']],
            'run with --phpunit without an input'           => [['rattle', 'run', '--phpunit', 'input']],
            'regress with --phpunit and a target'           => [['rattle', 'regress', '--phpunit', 'target.php']],
            'minimize with a filter'                        => [['rattle', 'minimize', '--phpunit', '--filter', 'Parser', 'Vendor\\ParserTest::testRoundTrip', 'input']],
            'number of jobs that is not a number'           => [['rattle', 'fuzz', 'target.php', '--jobs', 'many']],
            'seed that is not an integer'                   => [['rattle', 'fuzz', 'target.php', '--seed', '1.5']],
            'maximum time of zero'                          => [['rattle', 'fuzz', 'target.php', '--max-time', '0']],
            'unknown unit of time'                          => [['rattle', 'fuzz', 'target.php', '--max-time', '1w']],
            'negative maximum number of shrinking attempts' => [['rattle', 'fuzz', 'target.php', '--max-shrink-attempts', '-1']],
            'seed with run'                                 => [['rattle', 'run', 'target.php', 'input', '--seed', '1']],
            'SARIF with run'                                => [['rattle', 'run', 'target.php', 'input', '--sarif', 'findings.sarif']],
            'status without --phpunit'                      => [['rattle', 'status']],
            'status with an argument'                       => [['rattle', 'status', '--phpunit', 'target.php']],
            'status in JSON and HTML'                       => [['rattle', 'status', '--phpunit', '--json', '--html', 'status.html']],
            'empty SARIF file'                              => [['rattle', 'regress', 'target.php', '--sarif', '']],
        ];
    }

    public function testHasDefaults(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php']);

        $this->assertSame('fuzz', $arguments->command());
        $this->assertSame('target.php', $arguments->target());
        $this->assertNull($arguments->input());
        $this->assertNull($arguments->jobs());
        $this->assertNull($arguments->seed());
        $this->assertSame(1, $arguments->timeout());
        $this->assertNull($arguments->memoryLimit());
        $this->assertNull($arguments->maximumTime());
        $this->assertNull($arguments->maximumNumberOfExecutions());
        $this->assertSame(8192, $arguments->maximumNumberOfChoices());
        $this->assertSame(500, $arguments->maximumNumberOfShrinkingAttempts());
        $this->assertSame(10000, $arguments->numberOfExecutionsPerWorker());
        $this->assertSame([], $arguments->corpusDirectories());
        $this->assertNull($arguments->seedsDirectory());
        $this->assertNull($arguments->dictionary());
        $this->assertSame('.rattle.cache', $arguments->cacheDirectory());
        $this->assertSame('fuzz/findings', $arguments->findingsDirectory());
        $this->assertNull($arguments->bootstrap());
        $this->assertSame([], $arguments->sources());
        $this->assertFalse($arguments->ignoreDeprecations());
        $this->assertFalse($arguments->stopOnFinding());
        $this->assertFalse($arguments->verbose());
        $this->assertFalse($arguments->raw());
        $this->assertNull($arguments->inputLog());
        $this->assertNull($arguments->sarifFile());
        $this->assertSame([], $arguments->phpBinaries());
        $this->assertFalse($arguments->opcache());
        $this->assertFalse($arguments->help());
        $this->assertFalse($arguments->version());
    }

    public function testParsesTheOptionsOfTheFuzzCommand(): void
    {
        $arguments = (new ArgumentsBuilder)->build(
            [
                'rattle', 'fuzz', 'target.php',
                '--jobs', '4', '--seed', '-12', '--timeout', '3', '--memory-limit', '256M',
                '--max-time', '2h', '--max-executions', '1000', '--max-choices', '100',
                '--max-shrink-attempts', '0', '--worker-executions', '50',
                '--corpus', 'a', '--corpus', 'b', '--seeds', 'seeds', '--dictionary', 'dictionary.txt',
                '--cache-directory', 'cache', '--findings-directory', 'findings',
                '--bootstrap', 'bootstrap.php', '--source', 'src', '--source', 'lib',
                '--ignore-deprecations', '--stop-on-finding', '--verbose', '--log-inputs', 'inputs.log',
                '--sarif', 'findings.sarif', '--php', '/usr/bin/php8.6', '--php', '/usr/bin/php8.7', '--opcache',
            ],
        );

        $this->assertSame(4, $arguments->jobs());
        $this->assertSame(-12, $arguments->seed());
        $this->assertSame(3, $arguments->timeout());
        $this->assertSame('256M', $arguments->memoryLimit());
        $this->assertSame(7200.0, $arguments->maximumTime());
        $this->assertSame(1000, $arguments->maximumNumberOfExecutions());
        $this->assertSame(100, $arguments->maximumNumberOfChoices());
        $this->assertSame(0, $arguments->maximumNumberOfShrinkingAttempts());
        $this->assertSame(50, $arguments->numberOfExecutionsPerWorker());
        $this->assertSame(['a', 'b'], $arguments->corpusDirectories());
        $this->assertSame('seeds', $arguments->seedsDirectory());
        $this->assertSame('dictionary.txt', $arguments->dictionary());
        $this->assertSame('cache', $arguments->cacheDirectory());
        $this->assertSame('findings', $arguments->findingsDirectory());
        $this->assertSame('bootstrap.php', $arguments->bootstrap());
        $this->assertSame(['src', 'lib'], $arguments->sources());
        $this->assertTrue($arguments->ignoreDeprecations());
        $this->assertTrue($arguments->stopOnFinding());
        $this->assertTrue($arguments->verbose());
        $this->assertSame('inputs.log', $arguments->inputLog());
        $this->assertSame('findings.sarif', $arguments->sarifFile());
        $this->assertSame(['/usr/bin/php8.6', '/usr/bin/php8.7'], $arguments->phpBinaries());
        $this->assertTrue($arguments->opcache());
    }

    public function testParsesSeveralTargetsOfTheFuzzCommand(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'fuzz', 'fuzz/', 'other/target.php', '--jobs', '2']);

        $this->assertSame(['fuzz/', 'other/target.php'], $arguments->targets());
        $this->assertSame('fuzz/', $arguments->target());
        $this->assertNull($arguments->input());
        $this->assertSame(2, $arguments->jobs());
    }

    public function testParsesTheArgumentsOfTheRunCommand(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'run', '--raw', 'target.php', 'input', '--php', '/usr/bin/php8.6']);

        $this->assertSame('run', $arguments->command());
        $this->assertSame(['/usr/bin/php8.6'], $arguments->phpBinaries());
        $this->assertSame('target.php', $arguments->target());
        $this->assertSame(['target.php'], $arguments->targets());
        $this->assertSame('input', $arguments->input());
        $this->assertTrue($arguments->raw());
    }

    public function testReplacesWorkersLessOftenByDefaultWithOpcache(): void
    {
        $this->assertSame(100000, (new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php', '--opcache'])->numberOfExecutionsPerWorker());
        $this->assertSame(50, (new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php', '--opcache', '--worker-executions', '50'])->numberOfExecutionsPerWorker());
        $this->assertSame(100000, (new ArgumentsBuilder)->build(['rattle', 'regress', 'target.php', '--opcache'])->numberOfExecutionsPerWorker());
    }

    public function testParsesTheOptionsForFuzzingThePropertiesOfAPhpunitTestSuite(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'fuzz', '--phpunit', '--configuration', 'phpunit.xml', '--filter', 'Parser']);

        $this->assertSame('fuzz', $arguments->command());
        $this->assertNull($arguments->target());
        $this->assertTrue($arguments->phpunit());
        $this->assertSame('phpunit.xml', $arguments->phpunitConfiguration());
        $this->assertSame('Parser', $arguments->phpunitFilter());
    }

    public function testParsesTheArgumentsOfTheRunCommandForAProperty(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'run', '--phpunit', 'Vendor\ParserTest::testRoundTrip', 'input']);

        $this->assertTrue($arguments->phpunit());
        $this->assertSame('Vendor\ParserTest::testRoundTrip', $arguments->target());
        $this->assertSame('input', $arguments->input());
    }

    public function testParsesTheOptionsForReplayingTheInputsOfThePropertiesOfAPhpunitTestSuite(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'regress', '--phpunit', '--configuration', 'phpunit.xml', '--filter', 'Parser', '--sarif', 'findings.sarif', '--php', '/usr/bin/php8.6']);

        $this->assertSame('regress', $arguments->command());
        $this->assertSame(['/usr/bin/php8.6'], $arguments->phpBinaries());
        $this->assertSame('findings.sarif', $arguments->sarifFile());
        $this->assertNull($arguments->target());
        $this->assertTrue($arguments->phpunit());
        $this->assertSame('phpunit.xml', $arguments->phpunitConfiguration());
        $this->assertSame('Parser', $arguments->phpunitFilter());
    }

    public function testParsesTheOptionsOfTheStatusCommand(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'status', '--phpunit', '--cache-directory', 'cache', '--json']);

        $this->assertSame('status', $arguments->command());
        $this->assertTrue($arguments->phpunit());
        $this->assertSame('cache', $arguments->cacheDirectory());
        $this->assertTrue($arguments->json());
        $this->assertNull($arguments->htmlFile());
        $this->assertFalse((new ArgumentsBuilder)->build(['rattle', 'status', '--phpunit'])->json());
        $this->assertSame('status.html', (new ArgumentsBuilder)->build(['rattle', 'status', '--phpunit', '--html', 'status.html'])->htmlFile());
    }

    public function testParsesTheArgumentsOfTheMinimizeCommandForAProperty(): void
    {
        $arguments = (new ArgumentsBuilder)->build(['rattle', 'minimize', '--phpunit', '--configuration', 'phpunit.xml', 'Vendor\\ParserTest::testRoundTrip', 'input']);

        $this->assertTrue($arguments->phpunit());
        $this->assertSame('Vendor\\ParserTest::testRoundTrip', $arguments->target());
        $this->assertSame('input', $arguments->input());
    }

    #[DataProvider('durations')]
    public function testParsesDurations(string $duration, float $seconds): void
    {
        $this->assertSame($seconds, (new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php', '--max-time', $duration])->maximumTime());
    }

    public function testParsesHelpAndVersion(): void
    {
        $this->assertTrue((new ArgumentsBuilder)->build(['rattle', '--help'])->help());
        $this->assertTrue((new ArgumentsBuilder)->build(['rattle', '-h'])->help());
        $this->assertTrue((new ArgumentsBuilder)->build(['rattle', '--version'])->version());
        $this->assertTrue((new ArgumentsBuilder)->build(['rattle', '-v'])->version());
        $this->assertNull((new ArgumentsBuilder)->build(['rattle'])->command());
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArguments(array $argv): void
    {
        $this->expectException(ArgumentsBuilderException::class);

        (new ArgumentsBuilder)->build($argv);
    }
}
