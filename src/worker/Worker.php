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
use function array_values;
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
use SebastianBergmann\Rattle\Instrumentation\ComparisonOperands;
use SebastianBergmann\Rattle\Instrumentation\IncludeHook;
use SebastianBergmann\Rattle\Instrumentation\Instrumentor;
use Throwable;

/**
 * A long-running process that executes the inputs that the supervisor sends
 * it and reports one result per input as soon as it is available.
 *
 * At startup, a worker installs its shutdown function, registers the include
 * hook that instruments the code under test, loads the bootstrap file and the
 * target file, and tells the supervisor that it is ready. When the process
 * ends while an input is executed, because of a fatal error or because the
 * target calls exit(), the shutdown function reports a result for that input.
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
        $hook    = new IncludeHook(new Instrumentor($this->operands), $this->configuration->sources(), $phpunit === null ? [$this->configuration->target()] : []);

        try {
            $hook->register();

            $this->loadBootstrap();

            if ($phpunit !== null) {
                $api        = PhpunitApi::boot($phpunit->configurationFile(), $phpunit->filter());
                $properties = $api->properties();
                $name       = $this->configuration->target();

                if ($name === '') {
                    $this->stream->write(['type' => 'properties', 'protocol' => Frame::PROTOCOL_VERSION, 'properties' => $properties]);

                    return 0;
                }

                if (!in_array($name, $properties, true)) {
                    throw new WorkerException(sprintf('The PHPUnit test suite has no property "%s"', $name));
                }

                $maximumLength = null;
                $runner        = new PropertyExampleRunner($api, $name, $this->configuration->timeout(), $this->configuration->maximumNumberOfChoices(), $workingDirectory);
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

        $this->runner = $runner;

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

        $numberOfFailures = 0;
        $threshold        = $this->memoryThreshold();

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
                    $job = Job::fromArray(ProtocolData::array($input));

                    if (ob_get_level() === 0) {
                        $this->redirectOutput();
                    }

                    // the constant operands of the comparisons in the code
                    // that was instrumented, before the input or while it was
                    // executed, are reported with its result
                    $result = $runner->run($job)->withTokens($this->operands->takeUnreported())->asArray();

                    // output of the target is forwarded after each input
                    @ob_flush();

                    // the supervisor learns with the last result of a batch
                    // whether the worker exits after it, so that it does not
                    // send another batch to it
                    $retire = $index === $last &&
                              ($runner->numberOfExecutions() >= $this->configuration->numberOfExecutions() ||
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

        $this->stream->write(['type' => 'result', 'result' => $result->withTokens($this->operands->takeUnreported())->asArray(), 'last' => true]);
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
