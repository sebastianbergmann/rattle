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

use function assert;
use function count;
use function intdiv;
use function log;
use Random\Randomizer;

/**
 * Chooses the corpus entry that is mutated next.
 *
 * An entry is chosen with a probability that is proportional to its weight,
 * which is the product of three factors:
 *
 * - novelty: 1 + log2(1 + n), where n is the number of features the entry
 *   produced that were new when it was added, so that entries that found
 *   more new features are preferred, but not overwhelmingly
 * - brevity: 32 / (32 + length), where length is the number of choices, so
 *   that small entries are preferred: they are cheaper to execute and their
 *   mutations are more likely to change what matters
 * - recency: 1 + 4 / (1 + age), where age is the number of entries that were
 *   added after the entry, so that the newest entries, whose neighbourhood
 *   has been explored the least, are preferred for a while
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Scheduler
{
    private readonly Corpus $corpus;

    /**
     * The cumulative weights of the entries, computed when the corpus has
     * changed.
     *
     * @var list<float>
     */
    private array $cumulativeWeights = [];

    public static function weightOf(CorpusEntry $entry, int $numberOfEntries): float
    {
        $novelty = 1 + log(1 + count($entry->newFeatures()), 2);
        $brevity = 32 / (32 + $entry->length());
        $recency = 1 + 4 / (1 + $numberOfEntries - 1 - $entry->index());

        return $novelty * $brevity * $recency;
    }

    public function __construct(Corpus $corpus)
    {
        $this->corpus = $corpus;
    }

    public function choose(Randomizer $randomizer): CorpusEntry
    {
        $entries = $this->corpus->entries();

        assert($entries !== []);

        if (count($this->cumulativeWeights) !== count($entries)) {
            $this->computeWeights($entries);
        }

        $total    = $this->cumulativeWeights[count($this->cumulativeWeights) - 1] ?? 0.0;
        $selector = $randomizer->nextFloat() * $total;

        // the first entry whose cumulative weight exceeds the selector
        $low  = 0;
        $high = count($entries) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (($this->cumulativeWeights[$middle] ?? 0.0) > $selector) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        assert(isset($entries[$low]));

        return $entries[$low];
    }

    /**
     * @param list<CorpusEntry> $entries
     */
    private function computeWeights(array $entries): void
    {
        $this->cumulativeWeights = [];

        $total = 0.0;

        foreach ($entries as $entry) {
            $total += self::weightOf($entry, count($entries));

            $this->cumulativeWeights[] = $total;
        }
    }
}
