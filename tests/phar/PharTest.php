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

use const GLOB_ONLYDIR;
use const PHP_BINARY;
use function dirname;
use function file_put_contents;
use function glob;
use function hash_file;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHAR that "ant phar-snapshot" builds.
 */
#[CoversNothing]
#[Large]
final class PharTest extends TestCase
{
    private const string ROOT     = __DIR__ . '/../..';
    private const string PHAR     = self::ROOT . '/build/artifacts/rattle-snapshot.phar';
    private const string FIXTURES = 'tests/fixtures/phar';
    private string $directory;

    protected function setUp(): void
    {
        $this->assertFileExists(self::PHAR, 'Build the PHAR with "ant phar-snapshot" first');

        $this->directory = sys_get_temp_dir() . '/rattle-phar-' . uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testPrintsItsVersion(): void
    {
        [$exit, $output] = $this->rattle(['--version']);

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/^rattle \S+ by Sebastian Bergmann\.$/', $output);
    }

    public function testPrintsTheBundledPackages(): void
    {
        [$exit, $output] = $this->rattle(['--manifest']);

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/^sebastian\/rattle: /', $output);

        foreach (['nikic/php-parser', 'sebastian/choice-sequence', 'sebastian/cli-parser', 'sebastian/version'] as $package) {
            $this->assertStringContainsString("\n" . $package . ': ', $output);
        }
    }

    public function testPrintsTheSoftwareBillOfMaterials(): void
    {
        [$exit, $output] = $this->rattle(['--sbom']);

        $this->assertSame(0, $exit, $output);

        if ($output === '') {
            $this->fail('The PHAR printed no SBOM');
        }

        $document = new DOMDocument;

        $this->assertTrue($document->loadXML($output));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', 'http://cyclonedx.org/schema/bom/1.7');

        $this->assertSame('rattle', $xpath->evaluate('string(/c:bom/c:metadata/c:component/c:name)'));
        $this->assertSame(1.0, $xpath->evaluate('count(/c:bom/c:components/c:component[c:name="php-parser"])'));
    }

    public function testIsAccompaniedByASoftwareBillOfMaterialsForTheDownload(): void
    {
        $document = new DOMDocument;

        $this->assertTrue($document->load(self::PHAR . '.cdx.xml'));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', 'http://cyclonedx.org/schema/bom/1.7');

        $this->assertSame(hash_file('sha512', self::PHAR), $xpath->evaluate('string(/c:bom/c:metadata/c:component/c:hashes/c:hash[@alg="SHA-512"])'));
        $this->assertSame('https://phar.phpunit.de/rattle-snapshot.phar', $xpath->evaluate('string(/c:bom/c:metadata/c:component/c:externalReferences/c:reference[@type="distribution"]/c:url)'));
    }

    public function testPrintsTheComposerLockFileThatItWasBuiltWith(): void
    {
        [$exit, $output] = $this->rattle(['--composer-lock']);

        $this->assertSame(0, $exit, $output);
        $this->assertIsArray(json_decode($output, true));
    }

    public function testFuzzesAProjectThatUsesAVersionOfPhpParserThatIsNotCompatibleWithRattles(): void
    {
        // rattle's own copy of nikic/php-parser is prefixed in the PHAR, so
        // the project's PhpParser\ParserFactory is the one that its code uses
        [$exit, $output] = $this->rattle(
            [
                'fuzz',
                self::FIXTURES . '/target.php',
                '--jobs',
                '1',
                '--seed',
                '1',
                '--max-executions',
                '10000',
                '--stop-on-finding',
                '--bootstrap',
                self::FIXTURES . '/bootstrap.php',
                '--source',
                self::FIXTURES . '/src',
                '--cache-directory',
                $this->directory . '/cache',
                '--findings-directory',
                $this->directory . '/findings',
            ],
        );

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Finding: DivisionByZeroError at ' . self::FIXTURES . '/src/PhpParser/Parser.php:32', $output);

        $findings = glob($this->directory . '/findings/php-parser-4/*', GLOB_ONLYDIR);

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings);
        $this->assertStringEqualsFile($findings[0] . '/input.bin', '<?');
    }

    public function testLeavesItsOwnCodeOutOfStackTraces(): void
    {
        file_put_contents($this->directory . '/input.bin', '<?');

        [$exit, $output] = $this->rattle(
            [
                'run',
                '--raw',
                self::FIXTURES . '/target.php',
                $this->directory . '/input.bin',
                '--bootstrap',
                self::FIXTURES . '/bootstrap.php',
                '--source',
                self::FIXTURES . '/src',
            ],
        );

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Failure: DivisionByZeroError at ' . self::FIXTURES . '/src/PhpParser/Parser.php:32', $output);
        $this->assertStringContainsString(self::FIXTURES . '/target.php:', $output);
        $this->assertStringNotContainsString('phar://', $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function rattle(array $arguments): array
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, self::PHAR, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;

        $this->assertIsResource($process);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        // the standard error stream is read after the standard output stream,
        // which works as long as the workers do not write much to it
        $output = (string) stream_get_contents($stdout);
        $output .= (string) stream_get_contents($stderr);

        return [proc_close($process), $output];
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
