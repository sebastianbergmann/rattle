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
 * The constant operands of the comparisons in the files that a worker
 * instrumented (see ComparisonOperandFinder), of which the ones that were not
 * reported yet are reported with the next result.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class ComparisonOperands
{
    /**
     * @var array<string, true>
     */
    private array $found = [];

    /**
     * @var list<non-empty-string>
     */
    private array $unreported = [];

    /**
     * @param list<non-empty-string> $operands
     */
    public function add(array $operands): void
    {
        foreach ($operands as $operand) {
            if (!isset($this->found[$operand])) {
                $this->found[$operand] = true;
                $this->unreported[]    = $operand;
            }
        }
    }

    /**
     * The operands that were found since this was last called.
     *
     * @return list<non-empty-string>
     */
    public function takeUnreported(): array
    {
        $unreported = $this->unreported;

        $this->unreported = [];

        return $unreported;
    }
}
