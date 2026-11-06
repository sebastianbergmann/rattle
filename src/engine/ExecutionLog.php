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
 * Learns what the engine did with the result of every input that it
 * submitted, in the order in which it processed them, so that a run can be
 * followed input by input: where the input came from, its outcome, the path
 * that it took through the code that is instrumented (see Result), whether
 * it entered the corpus or replaced an entry of it, and whether it is a
 * finding.
 *
 * An engine with an execution log asks for the coverage and the drawn values
 * of every input. This does not change which inputs it submits.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
interface ExecutionLog
{
    /**
     * @param ?CorpusEntry $added    the entry that the input was added to the corpus as
     * @param ?CorpusEntry $replaced the entry that the input replaced an entry of the corpus with
     * @param ?bool        $finding  whether the input is a new finding (true), a known one (false), or none (null)
     */
    public function executed(Job $job, Result $result, Origin $origin, ?CorpusEntry $added, ?CorpusEntry $replaced, ?bool $finding): void;
}
