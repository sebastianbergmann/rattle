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

use function array_slice;
use Random\Randomizer;

/**
 * Mutates the choices of a corpus entry with one to five mutations that are
 * chosen at random. A mutation that cannot be applied, because the candidate
 * has no spans, for instance, is replaced with another one.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Mutator
{
    private const int LARGEST_NUMBER_OF_MUTATIONS = 5;
    private const int ATTEMPTS_PER_MUTATION       = 10;

    /**
     * @var non-empty-list<Mutation>
     */
    private array $mutations;

    /**
     * @var positive-int
     */
    private int $maximumNumberOfChoices;

    /**
     * The tokens of the dictionary are inserted even when it is empty when
     * fuzzing starts, because tokens are added to it while the target is
     * fuzzed.
     *
     * @param positive-int $maximumNumberOfChoices
     */
    public static function create(Corpus $corpus, Dictionary $dictionary, int $maximumNumberOfChoices): self
    {
        return new self(
            [
                new RandomChoice,
                new BoundaryChoice,
                new ArithmeticChoice,
                new DeleteSpan,
                new DuplicateSpan,
                new SwapSpans,
                new CrossOver($corpus),
                new InsertChoices,
                new DeleteChoices,
                new AddToken($dictionary),
            ],
            $maximumNumberOfChoices,
        );
    }

    /**
     * @param non-empty-list<Mutation> $mutations
     * @param positive-int             $maximumNumberOfChoices
     */
    public function __construct(array $mutations, int $maximumNumberOfChoices)
    {
        $this->mutations              = $mutations;
        $this->maximumNumberOfChoices = $maximumNumberOfChoices;
    }

    /**
     * @return list<non-negative-int>
     */
    public function mutate(CorpusEntry $entry, Randomizer $randomizer): array
    {
        $candidate = Candidate::from($entry->sequence());

        for ($i = $randomizer->getInt(1, self::LARGEST_NUMBER_OF_MUTATIONS); $i > 0; $i--) {
            for ($attempt = 0; $attempt < self::ATTEMPTS_PER_MUTATION; $attempt++) {
                if (RandomElement::of($randomizer, $this->mutations)->apply($candidate, $randomizer)) {
                    break;
                }
            }
        }

        return array_slice($candidate->choices(), 0, $this->maximumNumberOfChoices);
    }
}
