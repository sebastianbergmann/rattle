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

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use function array_map;
use function count;
use function fwrite;
use function json_encode;
use function microtime;
use function round;
use function strlen;
use function substr;
use Closure;

/**
 * Writes an execution log (see ExecutionLog) in JSON Lines, one event per
 * line, for tools that show how a run went input by input, such as an
 * animation of the code that the inputs reach. The events are:
 *
 * - start: the version of the format and the version of rattle, the target,
 *   the seed, the number of worker processes, and the time, in seconds since
 *   the Unix epoch
 * - file: a file that was instrumented, with its path relative to the
 *   working directory, its number of lines, and its blocks, each as its
 *   index and the line on which it starts, written once, before the first
 *   path that can take an edge into one of its blocks
 * - path: a path that inputs took, with the ID that the log gives it, and
 *   its edges, in the order in which they were first taken, each as the
 *   index of the block that the edge leaves, which is 0 for the entry point,
 *   the index of the block that it enters, and the bucket of the number of
 *   times it was taken: 0 for once, 1 for twice, 2 for three times, 3 for 4
 *   to 7, 4 for 8 to 15, 5 for 16 to 31, 6 for 32 to 127, and 7 for 128 times
 *   or more, written once, before the first execution that took it
 * - execution: an input whose result the engine processed, with its ID, the
 *   time since the log was started, in seconds, where it came from (initial,
 *   generated, or mutated, with the index of the corpus entry that it was
 *   mutated from and the names of the mutations), its outcome, the ID of its
 *   path, the values that it drew, of which each is cut off after 256
 *   bytes, the number of features that it added and the index of the corpus
 *   entry that it was added as or replaced, and whether it is a new or a
 *   known finding, and how it failed
 * - end: the time since the log was started, the number of executions, of
 *   features, of corpus entries, and of new findings
 *
 * Indexes of blocks have 28 bits, so that all numbers in the log can be read
 * exactly by JavaScript, unlike features, which are not written as they are.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class JsonLinesExecutionLog implements ExecutionLog
{
    public const int VERSION                  = 1;
    private const int MAXIMUM_LENGTH_OF_VALUE = 256;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;
    private readonly float $start;

    /**
     * The files that were written, by their paths.
     *
     * @var array<string, true>
     */
    private array $files = [];

    /**
     * The IDs of the paths that were written, by their hashes.
     *
     * @var array<string, non-negative-int>
     */
    private array $paths = [];

    /**
     * @param resource          $stream
     * @param ?Closure(): float $clock  returns the current time in seconds, by default microtime(true)
     */
    public function __construct($stream, ?Closure $clock = null)
    {
        $this->stream = $stream;
        $this->clock  = $clock ?? static fn (): float => microtime(true);
        $this->start  = ($this->clock)();
    }

    /**
     * @param non-empty-string $target
     * @param positive-int     $jobs   the number of worker processes
     */
    public function start(string $target, int $seed, int $jobs): void
    {
        $this->write(
            [
                'event'   => 'start',
                'version' => self::VERSION,
                'rattle'  => Application::version(),
                'target'  => $target,
                'seed'    => $seed,
                'jobs'    => $jobs,
                'time'    => $this->start,
            ],
        );
    }

    public function executed(Job $job, Result $result, Origin $origin, ?CorpusEntry $added, ?CorpusEntry $replaced, ?bool $finding): void
    {
        foreach ($result->blockMaps() as $map) {
            if (isset($this->files[$map->file()])) {
                continue;
            }

            $this->files[$map->file()] = true;

            $this->write(['event' => 'file', ...$map->asArray()]);
        }

        $execution = [
            'event'  => 'execution',
            'id'     => $job->id(),
            't'      => $this->elapsed(),
            'origin' => $origin->kind(),
        ];

        if ($origin->parent() !== null) {
            $execution['parent']    = $origin->parent();
            $execution['mutations'] = $origin->mutations();
        }

        $execution['outcome'] = $result->outcome()->value;

        $path = $this->pathOf($result);

        if ($path !== null) {
            $execution['path'] = $path;
        }

        if ($result->drawnValues() !== null) {
            $execution['values'] = array_map(self::shorten(...), $result->drawnValues());
        }

        if ($added !== null) {
            $execution['newFeatures'] = count($added->newFeatures());
            $execution['corpus']      = ['added' => $added->index()];
        } elseif ($replaced !== null) {
            $execution['corpus'] = ['replaced' => $replaced->index()];
        }

        if ($finding !== null) {
            $execution['finding'] = ['new' => $finding];

            if ($result->failure() !== null) {
                $execution['finding']['failure'] = $result->failure()->describe();
            }
        }

        $this->write($execution);
    }

    public function end(Statistics $statistics): void
    {
        $this->write(
            [
                'event'         => 'end',
                't'             => $this->elapsed(),
                'executions'    => $statistics->executions,
                'features'      => $statistics->features,
                'corpusEntries' => $statistics->corpusEntries,
                'findings'      => $statistics->findings,
            ],
        );
    }

    /**
     * The ID of the path that the input took, which is written first when it
     * is new. A path whose features are not known has no ID.
     *
     * @return ?non-negative-int
     */
    private function pathOf(Result $result): ?int
    {
        $hash = $result->path();

        if ($hash === null) {
            return null;
        }

        if (isset($this->paths[$hash])) {
            return $this->paths[$hash];
        }

        $features = $result->pathFeatures();

        if ($features === null) {
            return null;
        }

        $id = count($this->paths);

        $this->paths[$hash] = $id;

        $this->write(['event' => 'path', 'path' => $id, 'edges' => array_map(self::edgeOf(...), $features)]);

        return $id;
    }

    private function elapsed(): float
    {
        return round(($this->clock)() - $this->start, 6);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function write(array $event): void
    {
        fwrite(
            $this->stream,
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * The block that the edge of the feature leaves, the block that it
     * enters, and the bucket of the number of times it was taken.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function edgeOf(int $feature): array
    {
        return [($feature >> 28) & 0x0FFFFFFF, $feature & 0x0FFFFFFF, ($feature >> 56) & 0x7];
    }

    private static function shorten(string $value): string
    {
        if (strlen($value) <= self::MAXIMUM_LENGTH_OF_VALUE) {
            return $value;
        }

        return substr($value, 0, self::MAXIMUM_LENGTH_OF_VALUE) . '…';
    }
}
