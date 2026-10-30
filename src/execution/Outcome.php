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
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This enum is not covered by the backward compatibility promise for sebastian/rattle
 */
enum Outcome: string
{
    public function isFinding(): bool
    {
        return $this === self::Failed;
    }

    /**
     * The target returned.
     */
    case Passed = 'passed';

    /**
     * The target threw a throwable of an expected class.
     */
    case Expected = 'expected';

    /**
     * The input was rejected, which is not a finding.
     */
    case Rejected = 'rejected';

    /**
     * The input made the target fail: a finding.
     */
    case Failed = 'failed';
}
