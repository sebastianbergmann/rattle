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

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use function error_get_last;
use function in_array;
use function sprintf;
use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\Features;

/**
 * Executes the examples of a PHPUnit property: PHPUnit generates the
 * arguments of the property from the choices of an input, or from a seed,
 * runs the property with its time limit, and determines how it failed. When
 * a detector finds that an example controls a dangerous sink (see Detectors),
 * the detection is how the example fails, whatever PHPUnit determined.
 *
 * The result of an example that asks for its coverage identifies the path
 * that it took, whatever its outcome (see CoveredPaths).
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class PropertyExampleRunner implements InputRunner
{
    private const array FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    private readonly PhpunitApi $api;

    /**
     * @var non-empty-string
     */
    private readonly string $id;

    /**
     * @var positive-int
     */
    private readonly int $timeLimit;

    /**
     * @var positive-int
     */
    private readonly int $maximumNumberOfChoices;
    private readonly Location $location;
    private readonly NewFeatures $newFeatures;
    private readonly CoveredPaths $paths;
    private ?Job $job = null;

    /**
     * @var non-negative-int
     */
    private int $numberOfExecutions = 0;

    /**
     * @param non-empty-string $id                     the test ID of the property
     * @param positive-int     $timeLimit              in seconds, which PHPUnit enforces
     * @param positive-int     $maximumNumberOfChoices
     */
    public function __construct(PhpunitApi $api, string $id, int $timeLimit, int $maximumNumberOfChoices, string $workingDirectory)
    {
        $this->api                    = $api;
        $this->id                     = $id;
        $this->timeLimit              = $timeLimit;
        $this->maximumNumberOfChoices = $maximumNumberOfChoices;
        $this->location               = new Location($workingDirectory);
        $this->newFeatures            = new NewFeatures;
        $this->paths                  = new CoveredPaths;
    }

    /**
     * @throws WorkerException
     */
    public function run(Job $job): Result
    {
        $this->numberOfExecutions++;

        Edges::reset();

        $this->job = $job;

        Detectors::start();

        try {
            $choices = $job->choices();

            if ($choices !== null) {
                $example = $this->api->replay($this->id, $choices, $this->timeLimit, $job->reportDrawnValues());
            } else {
                $example = $this->api->generate($this->id, (int) $job->seed(), $this->timeLimit, $job->reportDrawnValues());
            }
        } catch (ProtocolException $e) {
            throw new WorkerException(sprintf('PHPUnit returned an invalid result: %s', $e->getMessage()), 0, $e);
        } finally {
            $detection = Detectors::stop();

            $this->job = null;
        }

        $edges   = Edges::hits();
        $outcome = $example->outcome();

        if ($detection !== null) {
            return $this->withPath($job, new Result($job->id(), Outcome::Failed, $detection, $example->sequence(), [], $example->values()), $edges);
        }

        if ($outcome->isFinding()) {
            return $this->withPath($job, new Result($job->id(), $outcome, $example->failure(), $example->sequence(), [], $example->values()), $edges);
        }

        $features                 = [];
        $producedRequiredFeatures = false;

        if ($outcome !== Outcome::Rejected) {
            $features                 = $job->reportAllFeatures() ? $this->newFeatures->all($edges) : $this->newFeatures->of($edges);
            $producedRequiredFeatures = $job->requiredFeatures() !== [] && Features::produces($edges, $job->requiredFeatures());
        }

        // the sequence of a terse input is only reported when it is needed
        $sequence = $job->isTerse() && $features === [] && !$producedRequiredFeatures ? null : $example->sequence();

        return $this->withPath($job, new Result($job->id(), $outcome, null, $sequence, $features, $example->values(), [], $producedRequiredFeatures), $edges);
    }

    /**
     * The choices that the example consumed before the process ended are
     * not known, so the result has the choices of the input.
     */
    public function resultOfInterruptedExecution(): ?Result
    {
        if ($this->job === null) {
            return null;
        }

        $failure = Detectors::detection();

        if ($failure === null) {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], self::FATAL_ERRORS, true)) {
                $failure = Failure::description(
                    'Fatal error at ' . $this->location->ofIssue($error['file'], $error['line']),
                    $error['message'],
                    '',
                );
            } else {
                $failure = Failure::exit();
            }
        }

        return $this->withPath($this->job, new Result($this->job->id(), Outcome::Failed, $failure, $this->job->sequence(), [], null), Edges::hits());
    }

    /**
     * @return non-negative-int
     */
    public function numberOfExecutions(): int
    {
        return $this->numberOfExecutions;
    }

    /**
     * @return positive-int
     */
    public function maximumNumberOfChoices(): int
    {
        return $this->maximumNumberOfChoices;
    }

    /**
     * @param array<int, positive-int> $edges
     */
    private function withPath(Job $job, Result $result, array $edges): Result
    {
        if (!$job->reportCoverage()) {
            return $result;
        }

        return $this->paths->addTo($result, $edges);
    }
}
