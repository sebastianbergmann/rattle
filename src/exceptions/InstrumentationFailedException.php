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

use function sprintf;
use RuntimeException;
use SebastianBergmann\Rattle\Exception;
use Throwable;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class InstrumentationFailedException extends RuntimeException implements Exception
{
    public function __construct(string $path, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Cannot instrument %s: %s',
                $path,
                $reason,
            ),
            0,
            $previous,
        );
    }
}
