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

/**
 * The choices that the choices of a corpus entry were mutated into, and the
 * names of the mutations that were applied to them, in the order in which
 * they were applied.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Mutant
{
    /**
     * @var list<non-negative-int>
     */
    private array $choices;

    /**
     * @var list<non-empty-string>
     */
    private array $mutations;

    /**
     * @param list<non-negative-int> $choices
     * @param list<non-empty-string> $mutations
     */
    public function __construct(array $choices, array $mutations)
    {
        $this->choices   = $choices;
        $this->mutations = $mutations;
    }

    /**
     * @return list<non-negative-int>
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * The names of the mutations that were applied, such as AddToken.
     *
     * @return list<non-empty-string>
     */
    public function mutations(): array
    {
        return $this->mutations;
    }
}
