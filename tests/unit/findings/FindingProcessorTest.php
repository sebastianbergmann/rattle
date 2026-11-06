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

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function preg_quote;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Decoder;
use SebastianBergmann\ChoiceSequence\FailureSignature;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

#[CoversClass(FindingProcessor::class)]
#[CoversClass(FindingStore::class)]
#[CoversClass(FindingException::class)]
#[UsesClass(Detectors::class)]
#[UsesClass(NewFeatures::class)]
#[UsesClass(Application::class)]
#[UsesClass(BytesEncoding::class)]
#[UsesClass(Edges::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Features::class)]
#[UsesClass(InProcessExecutor::class)]
#[UsesClass(Input::class)]
#[UsesClass(Job::class)]
#[UsesClass(Location::class)]
#[UsesClass(Minimization::class)]
#[UsesClass(Minimizer::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(ProtocolData::class)]
#[UsesClass(Result::class)]
#[UsesClass(Runner::class)]
#[UsesClass(Target::class)]
#[UsesClass(TargetDescription::class)]
#[UsesClass(ValueExporter::class)]
#[Small]
final class FindingProcessorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-findings-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*/*') as $file) {
            unlink((string) $file);
        }

        foreach ((array) glob($this->directory . '/*') as $directory) {
            rmdir((string) $directory);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testReproducesMinimizesAndStoresANewFinding(): void
    {
        $executor = $this->executor();
        $result   = $executor->execute(Job::replay(1, BytesEncoding::choicesFor('a long input with an x in it', 100)));
        $stored   = [];

        $processor = new FindingProcessor(
            new Minimizer($executor),
            new FindingStore($this->directory),
            $executor->target(),
            'fuzz/target.php',
            1234,
            static function (Minimization $minimization, string $directory) use (&$stored): void
            {
                $stored[] = $directory;
            },
        );

        $this->assertTrue($processor->handle($result, Job::replay(1, [])));

        $id        = FindingStore::idOf($this->notNull($result->failure())->signature());
        $directory = $this->directory . '/' . $id;

        $this->assertSame([$directory], $stored);
        $this->assertSame(hash('sha256', 'throwable' . "\n" . $this->notNull($result->failure())->describe()), $id);
        $this->assertSame([1, 0x78, 0], (new Decoder)->decode((string) file_get_contents($directory . '/input'))->choices());
        $this->assertSame($result->sequence()->choices(), (new Decoder)->decode((string) file_get_contents($directory . '/original'))->choices());
        $this->assertSame('x', file_get_contents($directory . '/input.bin'));
        $this->assertStringContainsString('rattle run fuzz/target.php ' . $directory . '/input', (string) file_get_contents($directory . '/README.txt'));

        $finding = json_decode((string) file_get_contents($directory . '/finding.json'), true);

        $this->assertIsArray($finding);

        $signature = $finding['signature'] ?? null;

        $this->assertIsString($signature);
        $this->assertMatchesRegularExpression('/\A' . preg_quote(LogicException::class, '/') . ' at tests\/unit\/findings\/FindingProcessorTest\.php:\d+\z/', $signature);
        $this->assertSame(
            ['throwable', "an 'x'", ["'x'"], 1234, false, true, Application::version()],
            [
                $finding['kind'] ?? null,
                $finding['message'] ?? null,
                $finding['drawnValues'] ?? null,
                $finding['seed'] ?? null,
                $finding['flaky'] ?? null,
                $finding['minimized'] ?? null,
                $finding['rattleVersion'] ?? null,
            ],
        );

        $this->assertSame([$directory . '/input'], (new FindingStore($this->directory))->inputs());
    }

    public function testCountsAFindingWithTheSameSignatureAsADuplicate(): void
    {
        $executor  = $this->executor();
        $processor = new FindingProcessor(new Minimizer($executor), new FindingStore($this->directory), $executor->target(), 'target.php', 1);

        $this->assertTrue($processor->handle($executor->execute(Job::replay(1, BytesEncoding::choicesFor('x', 100))), Job::replay(1, [])));
        $this->assertFalse($processor->handle($executor->execute(Job::replay(2, BytesEncoding::choicesFor('xx', 100))), Job::replay(2, [])));
        $this->assertTrue($processor->handle($executor->execute(Job::replay(3, BytesEncoding::choicesFor('y', 100))), Job::replay(3, [])));
        $this->assertSame(2, $processor->numberOfKnownFindings());
    }

    public function testFindingsThatAreStoredAreKnownInTheNextRun(): void
    {
        $executor = $this->executor();

        (new FindingProcessor(new Minimizer($executor), new FindingStore($this->directory), $executor->target(), 'target.php', 1))
            ->handle($executor->execute(Job::replay(1, BytesEncoding::choicesFor('x', 100))), Job::replay(1, []));

        $processor = new FindingProcessor(new Minimizer($executor), new FindingStore($this->directory), $executor->target(), 'target.php', 2);

        $this->assertSame(1, $processor->numberOfKnownFindings());
        $this->assertFalse($processor->handle($executor->execute(Job::replay(2, BytesEncoding::choicesFor('abcx', 100))), Job::replay(2, [])));
    }

    public function testRecreatesTheSignaturesOfEveryKindOfFinding(): void
    {
        $store = new FindingStore($this->directory);

        foreach ([Failure::timeout('m', 't'), Failure::description('Fatal error at file.php:1', 'm', ''), Failure::throwable(RuntimeException::class, 'file.php:2', 'm', 't')] as $failure) {
            $store->store(new Minimization(new ChoiceSequence([1]), new ChoiceSequence([1]), $failure, [], false, null, false), 'target.php', null, 1);
        }

        mkdir($this->directory . '/unreadable');
        file_put_contents($this->directory . '/unreadable/finding.json', 'not json');

        $known = $store->knownSignatures();

        $this->assertCount(3, $known);
        $this->assertTrue(FailureSignature::forTimeout()->isSameAs($this->signatureIn($known, 'timeout')));
        $this->assertTrue(FailureSignature::forThrowable(RuntimeException::class, 'file.php:2')->isSameAs($this->signatureIn($known, 'throwable')));
        $this->assertTrue(FailureSignature::forDescription('Fatal error at file.php:1')->isSameAs($this->signatureIn($known, 'description')));
        $this->assertStringContainsString('did not fail in the same way again', (string) file_get_contents($this->directory . '/' . FindingStore::idOf(FailureSignature::forTimeout()) . '/README.txt'));
        $this->assertFalse(is_file($this->directory . '/' . FindingStore::idOf(FailureSignature::forTimeout()) . '/input.bin'));
    }

    public function testCannotStoreAFindingWhereNoDirectoryCanBeCreated(): void
    {
        $this->expectException(FindingException::class);

        (new FindingStore('/dev/null/findings'))->store(
            new Minimization(new ChoiceSequence([1]), new ChoiceSequence([1]), Failure::timeout('', ''), [], false, null, false),
            'target.php',
            null,
            1,
        );
    }

    public function testKeepsTheInputAsItWasFoundWhenTheMinimalInputDidNotFailInTheSameWayAgain(): void
    {
        $store     = new FindingStore($this->directory);
        $directory = $store->store(
            new Minimization(new ChoiceSequence([1, 2]), new ChoiceSequence([1]), Failure::timeout('', ''), [], true, null, false),
            'target.php',
            null,
            1,
        );

        $this->assertSame($this->directory, $store->directory());
        $this->assertStringContainsString(
            'The minimal input did not fail in the same way again when it was replayed, so the input as it was found is kept.',
            (string) file_get_contents($directory . '/README.txt'),
        );
    }

    private function executor(): InProcessExecutor
    {
        return new InProcessExecutor(
            new Runner(
                Target::bytes('target', static function (string $bytes): void
                {
                    if (str_contains($bytes, 'x')) {
                        throw new LogicException("an 'x'");
                    }

                    if (str_contains($bytes, 'y')) {
                        throw new RuntimeException("a 'y'");
                    }
                }, 100),
                8192,
                0,
                false,
                dirname(__DIR__, 3),
            ),
        );
    }

    /**
     * @param list<FailureSignature> $signatures
     */
    private function signatureIn(array $signatures, string $kind): FailureSignature
    {
        foreach ($signatures as $signature) {
            if ($signature->kind() === $kind) {
                return $signature;
            }
        }

        $this->fail();
    }

    private function notNull(?Failure $failure): Failure
    {
        $this->assertNotNull($failure);

        return $failure;
    }
}
