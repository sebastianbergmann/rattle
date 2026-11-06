<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use function array_count_values;
use function count;

/**
 * The runtime of instrumented code: it counts how often each edge between
 * two basic blocks is taken while an input is executed.
 *
 * An edge is identified by the index of the block it leaves and the index of
 * the block it enters, as previous << 28 | block. Block indexes have 28 bits,
 * and 0 stands for the entry point, where an execution starts.
 *
 * Instrumented code appends every edge that it takes to the trace with a
 * statement where a block starts, or with a call of through() where a block
 * starts within an expression. Appending to a list is cheaper than counting
 * in a map, which needs to look the edge up first. The trace is counted when
 * the hits are asked for, and while an input is executed whenever it has
 * become long: by the statement at the start of a function body, a loop body,
 * and after a label, where code is entered again and again, and by through().
 * This way, the trace uses little memory however many blocks an input enters.
 * The trace and the counts are reset before every execution.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Edges
{
    /**
     * The number of edges in the trace above which it is counted while an
     * input is executed.
     */
    private const int MAXIMUM_LENGTH_OF_TRACE = 65536;

    /**
     * The index of the block that was entered last, shifted to the left by 28
     * bits, as it is part of the edges that leave the block.
     */
    public static int $previous = 0;

    /**
     * The edges that were taken since the trace was counted last, in the
     * order in which they were taken.
     *
     * @var list<int>
     */
    public static array $trace = [];

    /**
     * How often each edge was taken until the trace was counted last.
     *
     * @var array<int, positive-int>
     */
    private static array $counted = [];

    public static function reset(): void
    {
        self::$trace    = [];
        self::$counted  = [];
        self::$previous = 0;
    }

    /**
     * How often each edge was taken since the last reset, in the order in
     * which the edges were taken first.
     *
     * @return array<int, positive-int>
     *
     * @phpstan-impure
     */
    public static function hits(): array
    {
        self::countTrace();

        return self::$counted;
    }

    /**
     * Adds the edges in the trace to the counts, and empties the trace.
     */
    public static function countTrace(): void
    {
        if (self::$trace === []) {
            return;
        }

        /** @var array<int, positive-int> $counts */
        $counts      = array_count_values(self::$trace);
        self::$trace = [];

        if (self::$counted === []) {
            self::$counted = $counts;

            return;
        }

        foreach ($counts as $edge => $count) {
            self::$counted[$edge] = (self::$counted[$edge] ?? 0) + $count;
        }
    }

    /**
     * Enters a block within an expression and passes the value of the
     * expression through.
     *
     * @template T
     *
     * @param positive-int $block
     * @param T            $value
     *
     * @return T
     */
    public static function through(int $block, mixed $value): mixed
    {
        self::$trace[]  = self::$previous | $block;
        self::$previous = $block << 28;

        if (count(self::$trace) > self::MAXIMUM_LENGTH_OF_TRACE) {
            self::countTrace();
        }

        return $value;
    }

    /**
     * The statement that enters a block, as it is inserted into instrumented
     * code. It does not assign a variable, so that it does not change the
     * scope it is inserted into.
     *
     * @param positive-int $block
     * @param bool         $repeated whether the block is the start of a function body or a loop body, or follows a label, so that the statement counts the trace when it has become long
     */
    public static function statementFor(int $block, bool $repeated): string
    {
        $edges     = '\\' . self::class;
        $statement = $edges . '::$trace[] = ' . $edges . '::$previous | ' . $block . '; ' . $edges . '::$previous = ' . ($block << 28) . ';';

        if ($repeated) {
            $statement .= ' if (\count(' . $edges . '::$trace) > ' . self::MAXIMUM_LENGTH_OF_TRACE . ') { ' . $edges . '::countTrace(); }';
        }

        return $statement;
    }

    /**
     * The call that enters a block within an expression, up to the
     * expression, as it is inserted into instrumented code.
     *
     * @param positive-int $block
     */
    public static function callFor(int $block): string
    {
        return '\\' . self::class . '::through(' . $block . ', ';
    }
}
