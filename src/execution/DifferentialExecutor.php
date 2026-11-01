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

use function array_keys;
use function array_shift;
use function array_unique;
use function assert;
use function basename;
use function count;
use function implode;
use function in_array;
use function sprintf;

/**
 * Executes every input with the executors of several versions of PHP, and
 * reports when the input does not have the same outcome on all of them: when
 * it passes on one version and fails on another, or fails in another way. The
 * outcome on a version is what the input does there: it passes, also when the
 * target throws a throwable that it expects, it is rejected, or it fails, as
 * the failure signature of its failure describes it.
 *
 * A difference is reported as a failure of its own, described by the outcome
 * on every version, so that the same difference is the same finding, and it
 * is minimized like any other finding (see Minimizer). Its message has the
 * messages, and its stack trace the stack traces, of the versions on which
 * the input fails.
 *
 * The first executor is the one of the version that fuzzes. The consumed
 * choices and the drawn values of an input are the ones of that version.
 * Versions are told apart by their major and minor versions, and when two
 * executors run the same one, by the name of the binary of the second.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class DifferentialExecutor implements Executor
{
    /**
     * @var non-empty-array<non-empty-string, Executor>
     */
    private readonly array $executors;

    /**
     * The target that every input names, for executors that serve all the
     * properties of a PHPUnit test suite.
     *
     * @var ?non-empty-string
     */
    private readonly ?string $target;

    /**
     * @var list<Job>
     */
    private array $pending = [];

    /**
     * The names of versions of PHP: their major and minor versions, and when
     * two binaries run the same one, the name of the second binary as well.
     *
     * @param non-empty-list<PhpBinary> $binaries
     *
     * @return non-empty-list<non-empty-string>
     */
    public static function labelsOf(array $binaries): array
    {
        $labels = [];

        foreach ($binaries as $binary) {
            $label = $binary->label();

            if (in_array($label, $labels, true)) {
                $label .= ' (' . basename($binary->path()) . ')';
            }

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * The result of an input from its results on the versions, by the names
     * of the versions, the one of the version that fuzzes first: it passes
     * when it has the same outcome on all versions, and it fails with the
     * difference otherwise.
     *
     * @param non-negative-int                          $id
     * @param non-empty-array<non-empty-string, Result> $results
     */
    public static function differenceOf(int $id, array $results): Result
    {
        $first    = null;
        $outcomes = [];

        foreach ($results as $label => $result) {
            $first ??= $result;
            $outcomes[$label] = self::outcomeOf($result);
        }

        assert($first !== null);

        if (count(array_unique($outcomes)) === 1) {
            return new Result($id, Outcome::Passed, null, $first->sequence(), [], $first->drawnValues());
        }

        $versions = [];
        $messages = [];
        $traces   = [];

        foreach ($results as $label => $result) {
            $versions[] = sprintf('%s: %s', $label, $outcomes[$label] ?? '');
            $failure    = $result->failure();

            if ($failure === null) {
                continue;
            }

            if ($failure->message() !== '') {
                $messages[] = sprintf('%s: %s', $label, $failure->message());
            }

            if ($failure->trace() !== '') {
                $traces[] = sprintf("%s:\n%s", $label, $failure->trace());
            }
        }

        return new Result(
            $id,
            Outcome::Failed,
            Failure::description(
                'Difference between PHP versions: ' . implode('; ', $versions),
                implode("\n", $messages),
                implode("\n", $traces),
            ),
            $first->sequence(),
            [],
            $first->drawnValues(),
        );
    }

    /**
     * @param non-empty-array<non-empty-string, Executor> $executors the executors by the names of their versions, the one of the version that fuzzes first
     * @param ?non-empty-string                           $target    the property that every input is executed for
     */
    public function __construct(array $executors, ?string $target = null)
    {
        $this->executors = $executors;
        $this->target    = $target;
    }

    /**
     * An executor that executes the inputs of a property on the same versions,
     * with the same executors.
     *
     * @param non-empty-string $target the test ID of the property
     */
    public function forTarget(string $target): self
    {
        return new self($this->executors, $target);
    }

    /**
     * The names of the versions, the one that fuzzes first.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function labels(): array
    {
        return array_keys($this->executors);
    }

    public function target(): TargetDescription
    {
        foreach ($this->executors as $executor) {
            return $executor->target();
        }
    }

    public function capacity(): int
    {
        return $this->pending === [] ? 1 : 0;
    }

    public function submit(array $jobs): void
    {
        foreach ($jobs as $job) {
            $this->pending[] = $job;
        }
    }

    public function collect(): array
    {
        $results = [];

        while ($this->pending !== []) {
            $results[] = $this->execute(array_shift($this->pending));
        }

        return $results;
    }

    public function execute(Job $job): Result
    {
        return self::differenceOf($job->id(), $this->executeOnEach($job));
    }

    /**
     * Executes the input on every version, and returns the results by the
     * names of the versions.
     *
     * @return non-empty-array<non-empty-string, Result>
     */
    public function executeOnEach(Job $job): array
    {
        if ($this->target !== null) {
            $job = $job->withTarget($this->target);
        }

        $results = [];

        foreach ($this->executors as $label => $executor) {
            $results[$label] = $executor->execute($job);
        }

        return $results;
    }

    public function shutdown(): void
    {
        foreach ($this->executors as $executor) {
            $executor->shutdown();
        }
    }

    /**
     * @return non-empty-string
     */
    private static function outcomeOf(Result $result): string
    {
        $failure = $result->failure();

        if ($result->isFinding()) {
            assert($failure !== null);

            return $failure->describe();
        }

        return match ($result->outcome()) {
            Outcome::Rejected => 'rejects the input',
            default           => 'passes',
        };
    }
}
