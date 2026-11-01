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
 * An input controls code that PHP compiles and runs: the path of a file that
 * is included leads to the sentinel of the file system, or an object of the
 * sentinel class is created with new, for instance by code that eval()
 * evaluates.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class CodeInjection extends Vulnerability
{
}
