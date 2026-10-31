<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Phpunit;

use function count;
use LogicException;

final class Classifier
{
    /**
     * @param list<int> $values
     */
    public function classify(array $values): string
    {
        if (count($values) < 3) {
            return 'short';
        }

        if ($values[0] !== 7) {
            return 'a';
        }

        if ($values[1] !== 3) {
            return 'b';
        }

        if ($values[2] !== 9) {
            return 'c';
        }

        throw new LogicException('cannot classify');
    }
}
