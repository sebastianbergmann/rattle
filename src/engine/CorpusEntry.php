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

use function count;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

/**
 * An input in the corpus: the choice sequence it consumed, with spans and
 * maxima, the features it produced that were new when it was added, and when
 * it was added.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class CorpusEntry
{
    private ChoiceSequence $sequence;

    /**
     * @var list<int>
     */
    private array $newFeatures;

    /**
     * @var non-negative-int
     */
    private int $index;

    /**
     * @param list<int>        $newFeatures
     * @param non-negative-int $index       the number of entries that were added before
     */
    public function __construct(ChoiceSequence $sequence, array $newFeatures, int $index)
    {
        $this->sequence    = $sequence;
        $this->newFeatures = $newFeatures;
        $this->index       = $index;
    }

    public function sequence(): ChoiceSequence
    {
        return $this->sequence;
    }

    /**
     * @return list<int>
     */
    public function newFeatures(): array
    {
        return $this->newFeatures;
    }

    /**
     * @return non-negative-int
     */
    public function index(): int
    {
        return $this->index;
    }

    /**
     * @return non-negative-int
     */
    public function length(): int
    {
        return count($this->sequence->choices());
    }
}
