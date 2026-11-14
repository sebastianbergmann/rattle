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
use Random\Randomizer;

/**
 * Deletes the choices of a span, for instance an element of a collection.
 *
 * An element that the minimum size of a collection requires (see
 * SpanKind::RequiredElement) is deleted together with the choice 1 that
 * added the first element after the ones that the minimum size requires, as
 * the choices after its span would be read as the element otherwise. It is
 * not deleted when no choice added an element to its collection.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class DeleteSpan implements Mutation
{
    public function apply(Candidate $candidate, Randomizer $randomizer): bool
    {
        $spans = $candidate->spans();

        if ($spans === []) {
            return false;
        }

        $span = RandomElement::of($randomizer, $spans);

        // no choice added an element that the minimum size of a collection
        // requires: it is deleted together with the choice that added
        // another element, which takes its place
        if ($candidate->isRequired($span)) {
            return $candidate->deleteRequiredElement($span);
        }

        $length = $span['end'] - $span['start'];

        assert($length > 0);

        $candidate->delete($span['start'], $length);

        return true;
    }
}
