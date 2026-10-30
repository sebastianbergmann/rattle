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

/**
 * The runtime of instrumented code: it counts how often each edge between
 * two basic blocks is taken while an input is executed.
 *
 * An edge is identified by the index of the block it leaves and the index of
 * the block it enters, as previous << 28 | block. Block indexes have 28 bits,
 * and 0 stands for the entry point, where an execution starts.
 *
 * Instrumented code updates the static properties with a statement where a
 * block starts, or with a call of through() where a block starts within an
 * expression. The counts are reset before every execution.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Edges
{
    /**
     * The index of the block that was entered last.
     */
    public static int $previous = 0;

    /**
     * How often each edge was taken.
     *
     * @var array<int, positive-int>
     */
    public static array $hits = [];

    public static function reset(): void
    {
        self::$hits     = [];
        self::$previous = 0;
    }

    /**
     * Enters a block within an expression and passes the value of the
     * expression through.
     *
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    public static function through(int $block, mixed $value): mixed
    {
        $edge = self::$previous << 28 | $block;

        if (isset(self::$hits[$edge])) {
            self::$hits[$edge]++;
        } else {
            self::$hits[$edge] = 1;
        }

        self::$previous = $block;

        return $value;
    }

    /**
     * The statement that enters a block, as it is inserted into instrumented
     * code. It does not assign a variable, so that it does not change the
     * scope it is inserted into.
     *
     * @param positive-int $block
     */
    public static function statementFor(int $block): string
    {
        $edge = '\\' . self::class . '::$previous << 28 | ' . $block;
        $hit  = '\\' . self::class . '::$hits[' . $edge . ']';

        return 'isset(' . $hit . ') ? ++' . $hit . ' : (' . $hit . ' = 1); \\' . self::class . '::$previous = ' . $block . ';';
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
