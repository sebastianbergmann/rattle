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

use const SIG_IGN;
use const SIGINT;
use const STDERR;
use function array_keys;
use function array_values;
use function assert;
use function count;
use function fwrite;
use function getcwd;
use function getmypid;
use function in_array;
use function ini_get;
use function ini_set;
use function is_file;
use function memory_get_usage;
use function ob_flush;
use function ob_get_level;
use function ob_start;
use function pcntl_signal;
use function register_shutdown_function;
use function sprintf;
use function strtolower;
use function substr;
use function trim;
use Closure;
use SebastianBergmann\Rattle\Instrumentation\ComparisonOperands;
use SebastianBergmann\Rattle\Instrumentation\IncludeHook;
use SebastianBergmann\Rattle\Instrumentation\Instrumentor;
use Throwable;

/**
 * A long-running process that executes the inputs that the supervisor sends
 * it and reports one result per input as soon as it is available.
 *
 * At startup, a worker installs its shutdown function, registers the include
 * hook that instruments the code under test, installs the detectors of
 * dangerous sinks (see Detectors), loads the bootstrap file and the target
 * file, or the target files when there are several, and tells the supervisor
 * that it is ready, or describes the targets. When the process ends
 * while an input is executed, because of a fatal error or because the target
 * calls exit(), the shutdown function reports a result for that input.
 *
 * A worker exits after a batch of inputs once it has executed the configured
 * number of inputs or uses more memory than a threshold, to limit state that
 * leaks through static properties. The supervisor then starts a new one.
 *
 * Output of the target is redirected to the standard error stream, so that it
 * does not interfere with the frames on the standard output stream.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Worker
{
    private const int DEFAULT_MEMORY_THRESHOLD = 512 * 1024 * 1024;
    private readonly WorkerConfiguration $configuration;
    private readonly FrameStream $stream;
    private readonly ComparisonOperands $operands;
    private ?InputRunner $runner = null;

    /**
     * The target that the input that is executed names.
     */
    private ?string $target = null;

    /**
     * The constant operands of the comparisons in the code that was
     * instrumented while a worker with several targets started, by the
     * targets that they were not reported for yet.
     *
     * @var array<non-empty-string, list<non-empty-string>>
     */
    private array $startupTokens = [];

    public function __construct(WorkerConfiguration $configuration, FrameStream $stream)
    {
        $this->configuration = $configuration;
        $this->stream        = $stream;
        $this->operands      = new ComparisonOperands;
    }

    /**
     * @return 0|2
     */
    public function run(): int
    {
        // locations are relative to the working directory in which the
        // worker was started, whatever the code under test does with it
        $workingDirectory = (string) getcwd();

        $this->prepareProcess();

        $phpunit = $this->configuration->phpunit();
        $targets = $this->configuration->targets();
        $hook    = new IncludeHook(new Instrumentor($this->operands), $this->configuration->sources(), $phpunit === null ? ($targets === [] ? [$this->configuration->target()] : $targets) : []);

        try {
            $hook->register();

            // a detection is reported at the innermost frame in the code that
            // is instrumented
            Detectors::install(new Location($workingDirectory), $hook->shouldInstrument(...));

            $this->loadBootstrap();

            if ($phpunit !== null) {
                $api        = PhpunitApi::boot($phpunit->configurationFile(), $phpunit->filter());
                $properties = $api->properties();
                $name       = $this->configuration->target();

                // a worker without a target lists the properties and then
                // runs the examples of all of them, with a runner for each
                // property, so that the features that it has seen are kept
                // apart for every property
                if ($name === '') {
                    $this->stream->write(
                        [
                            'type'                   => 'properties',
                            'protocol'               => Frame::PROTOCOL_VERSION,
                            'properties'             => $properties,
                            'maximumNumberOfChoices' => $this->configuration->maximumNumberOfChoices(),
                        ],
                    );

                    $runners = [];

                    return $this->serve(
                        $hook,
                        function (Job $job) use ($api, $properties, $workingDirectory, &$runners): InputRunner
                        {
                            $property = $job->target();

                            if ($property === null || !in_array($property, $properties, true)) {
                                throw new ProtocolException('An input does not name a property of the test suite');
                            }

                            return $runners[$property] ??= new PropertyExampleRunner($api, $property, $this->configuration->timeout(), $this->configuration->maximumNumberOfChoices(), $workingDirectory);
                        },
                    );
                }

                if (!in_array($name, $properties, true)) {
                    throw new WorkerException(sprintf('The PHPUnit test suite has no property "%s"', $name));
                }

                $maximumLength = null;
                $runner        = new PropertyExampleRunner($api, $name, $this->configuration->timeout(), $this->configuration->maximumNumberOfChoices(), $workingDirectory);
            } elseif ($targets !== []) {
                // a worker with several target files describes all their
                // targets and then executes the inputs of all of them, with a
                // runner for each target, so that the features that it has
                // seen are kept apart for every target
                $runners = $this->loadTargets($targets, $workingDirectory);

                // the constant operands of the comparisons in the code that
                // was instrumented while the worker started belong to all
                // targets
                $tokens = $this->operands->takeUnreported();

                foreach (array_keys($runners) as $name) {
                    $this->startupTokens[$name] = $tokens;
                }

                return $this->serve(
                    $hook,
                    static function (Job $job) use ($runners): InputRunner
                    {
                        $target = $job->target();

                        if ($target === null || !isset($runners[$target])) {
                            throw new ProtocolException('An input does not name one of the targets of the worker');
                        }

                        return $runners[$target];
                    },
                );
            } else {
                $target        = (new TargetLoader)->load($this->configuration->target());
                $name          = $target->name();
                $maximumLength = $target->maximumLength();
                $runner        = new Runner(
                    $target,
                    $this->configuration->maximumNumberOfChoices(),
                    $this->configuration->timeout(),
                    $this->configuration->ignoreDeprecations(),
                    $workingDirectory,
                );
            }
        } catch (Throwable $t) {
            $this->stream->write(['type' => 'error', 'message' => $t->getMessage()]);

            return 2;
        }

        $this->stream->write(
            [
                'type'                   => 'ready',
                'protocol'               => Frame::PROTOCOL_VERSION,
                'pid'                    => getmypid(),
                'name'                   => $name,
                'maximumLength'          => $maximumLength,
                'maximumNumberOfChoices' => $runner->maximumNumberOfChoices(),
            ],
        );

        return $this->serve($hook, static fn (Job $job): InputRunner => $runner);
    }

    /**
     * Reports the input that was executed when the process ended.
     */
    public function shutdown(): void
    {
        $result = $this->runner?->resultOfInterruptedExecution();

        if ($result === null) {
            return;
        }

        // after the memory limit was exceeded, reporting the result needs memory
        ini_set('memory_limit', '-1');

        $this->stream->write(['type' => 'result', 'result' => $result->withTokens($this->tokensFor($this->target))->asArray(), 'last' => true]);
    }

    /**
     * Loads the target files, and returns a runner for each of their targets,
     * by the names of the targets, after describing the targets to the
     * supervisor.
     *
     * @param non-empty-list<string> $files
     *
     * @throws InvalidTargetException
     *
     * @return array<non-empty-string, Runner>
     */
    private function loadTargets(array $files, string $workingDirectory): array
    {
        $runners     = [];
        $loadedFrom  = [];
        $description = [];

        foreach ($files as $file) {
            $target = (new TargetLoader)->load($file);
            $name   = $target->name();

            // the corpus and the findings of a target are kept in directories
            // that are named after it
            if (isset($loadedFrom[$name])) {
                throw new InvalidTargetException(sprintf('The target files "%s" and "%s" both return a target named "%s"', $loadedFrom[$name], $file, $name));
            }

            $loadedFrom[$name] = $file;
            $runners[$name]    = new Runner(
                $target,
                $this->configuration->maximumNumberOfChoices(),
                $this->configuration->timeout(),
                $this->configuration->ignoreDeprecations(),
                $workingDirectory,
            );

            $description[] = [
                'file'                   => $file,
                'name'                   => $name,
                'maximumLength'          => $target->maximumLength(),
                'maximumNumberOfChoices' => $runners[$name]->maximumNumberOfChoices(),
            ];
        }

        $this->stream->write(
            [
                'type'     => 'targets',
                'protocol' => Frame::PROTOCOL_VERSION,
                'targets'  => $description,
            ],
        );

        return $runners;
    }

    /**
     * The constant operands of the comparisons in the code that was
     * instrumented that were not reported yet, which are reported with the
     * result of an input of the target. The ones that were found while a
     * worker with several targets started are reported with the first result
     * of each of its targets.
     *
     * @return list<non-empty-string>
     */
    private function tokensFor(?string $target): array
    {
        $tokens = $this->operands->takeUnreported();

        if ($target === null || !isset($this->startupTokens[$target])) {
            return $tokens;
        }

        $startup = $this->startupTokens[$target];

        unset($this->startupTokens[$target]);

        return [...$startup, ...$tokens];
    }

    /**
     * Executes the inputs that the supervisor sends, each with the runner
     * that is returned for it, until the supervisor shuts the worker down or
     * the worker retires.
     *
     * @param Closure(Job): InputRunner $runnerFor
     *
     * @return 0|2
     */
    private function serve(IncludeHook $hook, Closure $runnerFor): int
    {
        $numberOfFailures          = 0;
        $numberOfExecutions        = 0;
        $numberOfInstrumentedFiles = 0;
        $threshold                 = $this->memoryThreshold();

        while (true) {
            try {
                $message = $this->stream->read();

                if ($message === null || ($message['type'] ?? null) === 'shutdown') {
                    return 0;
                }

                if (($message['type'] ?? null) !== 'run') {
                    throw new ProtocolException('Unknown message');
                }

                $inputs = ProtocolData::array($message['inputs'] ?? null);
                $last   = count($inputs) - 1;
                $retire = false;

                foreach (array_values($inputs) as $index => $input) {
                    $job    = Job::fromArray(ProtocolData::array($input));
                    $runner = $runnerFor($job);

                    $this->runner = $runner;
                    $this->target = $job->target();

                    if (ob_get_level() === 0) {
                        $this->redirectOutput();
                    }

                    // the constant operands of the comparisons in the code
                    // that was instrumented, before the input or while it was
                    // executed, are reported with its result, and so is the
                    // number of files that were instrumented
                    $result = $runner->run($job)->withTokens($this->tokensFor($job->target()));

                    $instrumentedFiles = $hook->numberOfInstrumentedFiles() - $numberOfInstrumentedFiles;

                    assert($instrumentedFiles >= 0);

                    $numberOfInstrumentedFiles = $hook->numberOfInstrumentedFiles();
                    $result                    = $result->withInstrumentedFiles($instrumentedFiles)->asArray();

                    $numberOfExecutions++;

                    // output of the target is forwarded after each input
                    @ob_flush();

                    // the supervisor learns with the last result of a batch
                    // whether the worker exits after it, so that it does not
                    // send another batch to it
                    $retire = $index === $last &&
                              ($numberOfExecutions >= $this->configuration->numberOfExecutions() ||
                               memory_get_usage() > $threshold);

                    $this->stream->write(['type' => 'result', 'result' => $result, 'retire' => $retire]);
                }
            } catch (ProtocolException|WorkerException $e) {
                $this->stream->write(['type' => 'error', 'message' => $e->getMessage()]);

                return 2;
            }

            if (count($hook->failures()) > $numberOfFailures) {
                foreach ($hook->failures() as $failure) {
                    fwrite(STDERR, $failure . "\n");
                }

                $numberOfFailures = count($hook->failures());
            }

            if ($retire) {
                return 0;
            }
        }
    }

    private function prepareProcess(): void
    {
        ini_set('display_errors', 'stderr');
        ini_set('log_errors', '0');
        ini_set('html_errors', '0');

        if ($this->configuration->memoryLimit() !== null) {
            ini_set('memory_limit', $this->configuration->memoryLimit());
        }

        register_shutdown_function($this->shutdown(...));

        // Ctrl+C in a terminal interrupts the supervisor, which stops the
        // workers, and must not end a worker while it executes an input
        pcntl_signal(SIGINT, SIG_IGN);

        $this->redirectOutput();
    }

    private function redirectOutput(): void
    {
        ob_start(
            static function (string $buffer): string
            {
                if ($buffer !== '') {
                    fwrite(STDERR, $buffer);
                }

                return '';
            },
            8192,
        );
    }

    /**
     * @throws WorkerException
     */
    private function loadBootstrap(): void
    {
        $bootstrap = $this->configuration->bootstrap();

        if ($bootstrap === null) {
            return;
        }

        if (!is_file($bootstrap)) {
            throw new WorkerException(sprintf('The bootstrap file "%s" does not exist', $bootstrap));
        }

        (static function (string $__bootstrap): void
        {
            require_once $__bootstrap;
        })($bootstrap);
    }

    /**
     * Half of the memory limit, or 512 MB without a memory limit.
     */
    private function memoryThreshold(): int
    {
        $limit = self::bytes((string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return self::DEFAULT_MEMORY_THRESHOLD;
        }

        return (int) ($limit / 2);
    }

    private static function bytes(string $value): int
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $factor = match (substr($value, -1)) {
            'k'     => 1024,
            'm'     => 1024 * 1024,
            'g'     => 1024 * 1024 * 1024,
            default => 1,
        };

        if ($factor > 1) {
            $value = substr($value, 0, -1);
        }

        return (int) $value * $factor;
    }
}
