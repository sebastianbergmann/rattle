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

use function sprintf;
use RuntimeException;

/**
 * Thrown by Input::reject(), and by Input when more choices are drawn than
 * permitted, to discard the input. A rejected input is not a finding.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 */
final class InputRejectedException extends RuntimeException implements Exception
{
    public static function byTarget(): self
    {
        return new self('The input was rejected by the target');
    }

    /**
     * @param positive-int $maximumNumberOfChoices
     */
    public static function tooManyChoices(int $maximumNumberOfChoices): self
    {
        return new self(
            sprintf(
                'The input was rejected because it needs more than %d choices',
                $maximumNumberOfChoices,
            ),
        );
    }
}
