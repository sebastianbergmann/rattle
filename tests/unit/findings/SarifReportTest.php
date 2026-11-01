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

use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SarifReport::class)]
#[UsesClass(Application::class)]
#[UsesClass(Failure::class)]
#[UsesClass(FindingStore::class)]
#[Small]
final class SarifReportTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: Failure, 3: array{0: ?string, 1: ?string, 2: ?int}}>
     */
    public static function failures(): array
    {
        return [
            'a path traversal' => [
                'path-traversal',
                'error',
                Failure::throwable(PathTraversal::class, 'src/TemplateLoader.php:30', 'The path "x" leads to the sentinel', ''),
                ['src/TemplateLoader.php', '%SRCROOT%', 30],
            ],
            'a code injection' => [
                'code-injection',
                'error',
                Failure::throwable(CodeInjection::class, 'src/Calculator.php:20', '', ''),
                ['src/Calculator.php', '%SRCROOT%', 20],
            ],
            'a command injection' => [
                'command-injection',
                'error',
                Failure::throwable(CommandInjection::class, 'src/Thumbnailer.php:56', '', ''),
                ['src/Thumbnailer.php', '%SRCROOT%', 56],
            ],
            'an unsafe deserialization' => [
                'unsafe-deserialization',
                'error',
                Failure::throwable(UnsafeDeserialization::class, 'src/Session.php:26', '', ''),
                ['src/Session.php', '%SRCROOT%', 26],
            ],
            'an uncaught throwable outside of the working directory' => [
                'uncaught-throwable',
                'warning',
                Failure::throwable(LogicException::class, '/opt/library/My File.php:7', 'message', ''),
                ['file:///opt/library/My%20File.php', null, 7],
            ],
            'a warning' => [
                'issue',
                'warning',
                Failure::description('Warning at src/IntegerColumn.php:46', 'message', ''),
                ['src/IntegerColumn.php', '%SRCROOT%', 46],
            ],
            'a fatal error' => [
                'fatal-error',
                'warning',
                Failure::description('Fatal error at src/Decoder.php:35', 'Allowed memory size exhausted', ''),
                ['src/Decoder.php', '%SRCROOT%', 35],
            ],
            'a timeout, at the first frame of its stack trace' => [
                'timeout',
                'warning',
                Failure::timeout('Execution exceeded the time limit of 1 second', 'src/Loop.php:21 Loop->run()'),
                ['src/Loop.php', '%SRCROOT%', 21],
            ],
            'the end of the process, without a location' => [
                'process-ended',
                'warning',
                Failure::processEnded(null, 3),
                [null, null, null],
            ],
        ];
    }

    /**
     * @param array{0: ?string, 1: ?string, 2: ?int} $location
     */
    #[DataProvider('failures')]
    public function testReportsAFindingAsAResultOfTheRuleOfItsKindAtItsLocation(string $rule, string $level, Failure $failure, array $location): void
    {
        $report = new SarifReport('/project');

        $report->add($failure, null);

        $json    = $report->asJson();
        $results = SarifResults::in($json);
        $result  = $results[0] ?? null;

        $this->assertCount(1, $results);
        $this->assertNotNull($result);
        $this->assertSame($rule, $result['ruleId']);
        $this->assertSame($rule, SarifResults::value($json, 'runs', 0, 'tool', 'driver', 'rules', (int) $result['ruleIndex'], 'id'));
        $this->assertSame($level, $result['level']);
        $this->assertSame(FindingStore::idOf($failure->signature()), $result['fingerprint']);
        $this->assertStringStartsWith($failure->describe(), (string) $result['message']);
        $this->assertSame($location, [$result['uri'], $result['uriBaseId'], $result['startLine']]);
        $this->assertNull(SarifResults::value($json, 'runs', 0, 'results', 0, 'locations', 0, 'message'));
    }

    public function testDescribesTheRulesOfTheVulnerabilitiesAsSecurityErrorsWithTheirWeaknesses(): void
    {
        $rules = SarifResults::rules((new SarifReport('/project'))->asJson());

        $this->assertCount(9, $rules);
        $this->assertSame(['level' => 'error', 'securitySeverity' => '7.5', 'tags' => ['security', 'external/cwe/cwe-22']], $rules['path-traversal'] ?? null);
        $this->assertSame(['level' => 'error', 'securitySeverity' => '9.3', 'tags' => ['security', 'external/cwe/cwe-94']], $rules['code-injection'] ?? null);
        $this->assertSame(['level' => 'error', 'securitySeverity' => '9.8', 'tags' => ['security', 'external/cwe/cwe-78']], $rules['command-injection'] ?? null);
        $this->assertSame(['level' => 'error', 'securitySeverity' => '9.8', 'tags' => ['security', 'external/cwe/cwe-502']], $rules['unsafe-deserialization'] ?? null);
        $this->assertSame(['level' => 'warning', 'securitySeverity' => null, 'tags' => ['correctness']], $rules['uncaught-throwable'] ?? null);
    }

    public function testReportsAFindingOnceWithTheStackTraceAndThePropertyOfItsFirstFailure(): void
    {
        $report  = new SarifReport('/project');
        $failure = Failure::throwable(PathTraversal::class, 'src/TemplateLoader.php:30', 'first', "src/TemplateLoader.php:30 is_file()\n(the frame above is repeated 3 more times)\ntests/TemplatesTest.php:12 TemplateLoader->load()");

        $report->add($failure, 'TemplatesTest::testLoads');
        $report->add(Failure::throwable(PathTraversal::class, 'src/TemplateLoader.php:30', 'second', ''), 'TemplatesTest::testLoads');

        $json    = $report->asJson();
        $results = SarifResults::in($json);
        $result  = $results[0] ?? null;

        $this->assertSame(1, $report->numberOfResults());
        $this->assertCount(1, $results);
        $this->assertNotNull($result);
        $this->assertSame('The property TemplatesTest::testLoads fails. ' . PathTraversal::class . ' at src/TemplateLoader.php:30: first', $result['message']);
        $this->assertSame('TemplatesTest::testLoads', $result['property']);
        $this->assertSame(
            [
                ['src/TemplateLoader.php', 30, 'is_file()'],
                ['tests/TemplatesTest.php', 12, 'TemplateLoader->load()'],
            ],
            $result['frames'],
        );
        $this->assertSame('file:///project/', SarifResults::value($json, 'runs', 0, 'originalUriBaseIds', '%SRCROOT%', 'uri'));
        $this->assertSame('rattle', SarifResults::value($json, 'runs', 0, 'tool', 'driver', 'name'));
        $this->assertSame('2.1.0', SarifResults::value($json, 'version'));
        $this->assertSame('https://json.schemastore.org/sarif-2.1.0.json', SarifResults::value($json, '$schema'));
    }

    public function testWritesTheReportToAFile(): void
    {
        $file   = sys_get_temp_dir() . '/rattle-sarif-' . uniqid() . '.sarif';
        $report = new SarifReport('/project');

        $report->write($file);

        $results = SarifResults::inFile($file);

        unlink($file);

        $this->assertSame([], $results);
    }

    public function testCannotWriteTheReportToADirectoryThatDoesNotExist(): void
    {
        $this->expectException(FindingException::class);

        (new SarifReport('/project'))->write('/does/not/exist/report.sarif');
    }
}
