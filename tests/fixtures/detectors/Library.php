<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Detectors;

use SebastianBergmann\Rattle\Detectors;
use SebastianBergmann\Rattle\PathTraversal;
use SebastianBergmann\Rattle\Vulnerability;

/**
 * Stands for code that the code under test uses and that calls a sink.
 */
final class Library
{
    public static function sink(): Vulnerability
    {
        return Detectors::detect(PathTraversal::class, 'a detection in a library');
    }
}
