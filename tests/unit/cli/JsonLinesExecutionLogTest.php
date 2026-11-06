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

use const JSON_THROW_ON_ERROR;
use function array_shift;
use function explode;
use function fopen;
use function json_decode;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function trim;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\Rattle\Instrumentation\BlockMap;

#[CoversClass(JsonLinesExecutionLog::class)]
#[UsesClass(Application::class)]
#[UsesClass(BlockMap::class)]
#[UsesClass(CorpusEntry::class)]
#[UsesClass(Failure::class)]
#[UsesClass(Job::class)]
#[UsesClass(Origin::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[UsesClass(Statistics::class)]
#[Small]
final class JsonLinesExecutionLogTest extends TestCase
{
    public function testWritesTheStartOfTheRun(): void
    {
        $stream = $this->stream();
        $log    = new JsonLinesExecutionLog($stream, $this->clock([1000.5]));

        $log->start('csv-parser', 42, 4);

        $this->assertSame(
            [
                [
                    'event'   => 'start',
                    'version' => 1,
                    'rattle'  => Application::version(),
                    'target'  => 'csv-parser',
                    'seed'    => 42,
                    'jobs'    => 4,
                    'time'    => 1000.5,
                ],
            ],
            $this->events($stream),
        );
    }

    public function testWritesEveryExecutionWithTheFilesAndThePathsThatItIsTheFirstToReport(): void
    {
        $stream = $this->stream();
        $log    = new JsonLinesExecutionLog($stream, $this->clock([1000.0, 1000.25, 1000.5, 1001.0]));
        $a      = new BlockMap('src/A.php', 12, [[5, 3], [7, 8]]);
        $b      = new BlockMap('src/B.php', 4, [[9, 2]]);

        $log->executed(
            Job::replay(0, [1, 2], true),
            (new Result(0, Outcome::Passed, null, new ChoiceSequence([1, 2]), [1], ["'ab'"]))
                ->withBlockMaps([$a])
                ->withPath('first', [5, 1 << 56 | 5 << 28 | 7]),
            Origin::initial(),
            new CorpusEntry(new ChoiceSequence([1, 2]), [5, 1 << 56 | 5 << 28 | 7], 0),
            null,
            null,
        );

        // another worker reports the same file and the same path again
        $log->executed(
            Job::generate(1, 99, true),
            (new Result(1, Outcome::Expected, null, null, [], ['42']))
                ->withBlockMaps([$a, $b])
                ->withPath('first', [5, 1 << 56 | 5 << 28 | 7]),
            Origin::generated(),
            null,
            null,
            null,
        );

        $log->executed(
            Job::replay(2, [1], true),
            (new Result(2, Outcome::Failed, Failure::description('Warning at src/A.php:8', 'a warning', ''), new ChoiceSequence([1]), [], ['true']))
                ->withPath('second', [7 << 56 | 9]),
            Origin::mutated(0, ['DeleteChoices', 'AddToken']),
            null,
            new CorpusEntry(new ChoiceSequence([1]), [5], 0),
            true,
        );

        $this->assertSame(
            [
                ['event' => 'file', 'file' => 'src/A.php', 'lines' => 12, 'blocks' => [[5, 3], [7, 8]]],
                ['event' => 'path', 'path' => 0, 'edges' => [[0, 5, 0], [5, 7, 1]]],
                ['event' => 'execution', 'id' => 0, 't' => 0.25, 'origin' => 'initial', 'outcome' => 'passed', 'path' => 0, 'values' => ["'ab'"], 'newFeatures' => 2, 'corpus' => ['added' => 0]],
                ['event' => 'file', 'file' => 'src/B.php', 'lines' => 4, 'blocks' => [[9, 2]]],
                ['event' => 'execution', 'id' => 1, 't' => 0.5, 'origin' => 'generated', 'outcome' => 'expected', 'path' => 0, 'values' => ['42']],
                ['event' => 'path', 'path' => 1, 'edges' => [[0, 9, 7]]],
                ['event' => 'execution', 'id' => 2, 't' => 1.0, 'origin' => 'mutated', 'parent' => 0, 'mutations' => ['DeleteChoices', 'AddToken'], 'outcome' => 'failed', 'path' => 1, 'values' => ['true'], 'corpus' => ['replaced' => 0], 'finding' => ['new' => true, 'failure' => 'Warning at src/A.php:8']],
            ],
            $this->events($stream),
        );
    }

    public function testWritesAnExecutionWithoutAPathWhenTheInputDidNotAskForItsCoverageOrItsPathIsNotKnown(): void
    {
        $stream = $this->stream();
        $log    = new JsonLinesExecutionLog($stream, $this->clock([0.0, 1.0, 2.0]));

        $log->executed(Job::replay(0, []), new Result(0, Outcome::Rejected, null, null, [], null), Origin::generated(), null, null, null);
        $log->executed(Job::replay(1, []), (new Result(1, Outcome::Passed, null, null, [], null))->withPath('reported', null), Origin::generated(), null, null, false);

        $this->assertSame(
            [
                ['event' => 'execution', 'id' => 0, 't' => 1.0, 'origin' => 'generated', 'outcome' => 'rejected'],
                ['event' => 'execution', 'id' => 1, 't' => 2.0, 'origin' => 'generated', 'outcome' => 'passed', 'finding' => ['new' => false]],
            ],
            $this->events($stream),
        );
    }

    public function testCutsOffLongValues(): void
    {
        $stream = $this->stream();
        $log    = new JsonLinesExecutionLog($stream, $this->clock([0.0, 0.0]));

        $log->executed(Job::replay(0, [], true), new Result(0, Outcome::Passed, null, null, [], [str_repeat('a', 256), str_repeat('b', 257)]), Origin::generated(), null, null, null);

        $this->assertSame([str_repeat('a', 256), str_repeat('b', 256) . '…'], $this->events($stream)[0]['values'] ?? null);
    }

    public function testWritesTheEndOfTheRun(): void
    {
        $stream     = $this->stream();
        $log        = new JsonLinesExecutionLog($stream, $this->clock([10.0, 12.5]));
        $statistics = new Statistics;

        $statistics->executions    = 100;
        $statistics->features      = 20;
        $statistics->corpusEntries = 7;
        $statistics->findings      = 1;

        $log->end($statistics);

        $this->assertSame(
            [['event' => 'end', 't' => 2.5, 'executions' => 100, 'features' => 20, 'corpusEntries' => 7, 'findings' => 1]],
            $this->events($stream),
        );
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        return $stream;
    }

    /**
     * @param list<float> $times
     *
     * @return Closure(): float
     */
    private function clock(array $times): Closure
    {
        return static function () use (&$times): float
        {
            return array_shift($times) ?? 0.0;
        };
    }

    /**
     * @param resource $stream
     *
     * @return list<array<string, mixed>>
     */
    private function events($stream): array
    {
        rewind($stream);

        $events = [];

        foreach (explode("\n", trim((string) stream_get_contents($stream))) as $line) {
            /** @var array<string, mixed> $event */
            $event    = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $events[] = $event;
        }

        return $events;
    }
}
