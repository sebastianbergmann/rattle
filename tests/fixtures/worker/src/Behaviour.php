<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Worker;

use const E_USER_WARNING;
use const SIGKILL;
use const STDOUT;
use function file_get_contents;
use function fwrite;
use function getmypid;
use function pcntl_alarm;
use function posix_kill;
use function str_repeat;
use function trigger_error;
use DomainException;
use LogicException;
use Throwable;

/**
 * Behaves in one of several ways that a worker has to survive or report.
 */
final class Behaviour
{
    public const int PASS               = 0;
    public const int WARNING            = 1;
    public const int FATAL_ERROR        = 2;
    public const int ENDLESS_LOOP       = 3;
    public const int EXIT               = 4;
    public const int KILL               = 5;
    public const int EXCEPTION          = 6;
    public const int REJECT             = 7;
    public const int UNINTERRUPTIBLE    = 8;
    public const int EXPECTED_EXCEPTION = 9;
    public const int OUTPUT             = 10;
    public const int STDOUT             = 11;
    public const int TRAVERSAL          = 12;
    public const int INCLUSION          = 13;

    public function run(int $behaviour, bool $flag): string
    {
        switch ($behaviour) {
            case self::PASS:
                if ($flag) {
                    return 'flag';
                }

                return 'pass';

            case self::WARNING:
                trigger_error('a warning', E_USER_WARNING);

                return 'warning';

            case self::FATAL_ERROR:
                return str_repeat('x', 1024 * 1024 * 1024);

            case self::ENDLESS_LOOP:
                while ($flag || !$flag) {
                    $flag = !$flag;
                }

                return 'unreachable';

            case self::EXIT:
                exit(3);

            case self::KILL:
                posix_kill((int) getmypid(), SIGKILL);

                return 'unreachable';

            case self::EXCEPTION:
                throw new LogicException('an exception');

            case self::UNINTERRUPTIBLE:
                // disarms the time limit of the worker, so that only the
                // supervisor's watchdog can end the execution
                pcntl_alarm(0);

                while ($flag || !$flag) {
                    $flag = !$flag;
                }

                return 'unreachable';

            case self::EXPECTED_EXCEPTION:
                throw new DomainException('an expected exception');

            case self::OUTPUT:
                print 'output that must not interfere with the protocol';

                return 'output';

            case self::STDOUT:
                fwrite(STDOUT, 'output that interferes with the protocol');

                return 'stdout';

            case self::TRAVERSAL:
                // reads a file whose path climbs to the sentinel of the file
                // system, and swallows what is thrown when the flag is set
                try {
                    return (string) file_get_contents(__DIR__ . '/' . str_repeat('../', 16) . 'rattle-sentinel');
                } catch (Throwable $t) {
                    if ($flag) {
                        return 'swallowed';
                    }

                    throw $t;
                }

            case self::INCLUSION:
                return (string) include __DIR__ . '/' . str_repeat('../', 16) . 'rattle-sentinel.php';
        }

        return 'other';
    }

    /**
     * Compares a result with a constant, which the worker reports as a token
     * once it has instrumented this file.
     */
    public function passed(string $result): bool
    {
        return $result === 'pass';
    }
}
