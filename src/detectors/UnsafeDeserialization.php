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
 * An input controls which classes the code under test unserializes objects
 * of: unserialize() created an object of the sentinel class. Data like this
 * can create an object of every class that can be loaded, and run the code of
 * its magic methods.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class UnsafeDeserialization extends Vulnerability
{
}
