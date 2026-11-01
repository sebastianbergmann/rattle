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

/**
 * The sentinel class of the detectors (see Detectors): no code creates an
 * object of it, unless an input makes it.
 *
 * When unserialize() creates an object of it from serialized data, the input
 * controls which classes the code under test unserializes objects of. When
 * an object of it is created with new, the input controls code that PHP
 * compiles and runs, such as the code that eval() evaluates. Either is
 * detected even when rattle does not run the code under test, for instance
 * when PHPUnit runs a property with a finding of rattle as its example.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Sentinel
{
    /**
     * @throws CodeInjection
     */
    public function __construct()
    {
        throw Detectors::detect(
            CodeInjection::class,
            sprintf('Code from the input created an object of the sentinel class %s', self::class),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws UnsafeDeserialization
     */
    public function __unserialize(array $data): void
    {
        throw Detectors::detect(
            UnsafeDeserialization::class,
            sprintf('Serialized data from the input created an object of the sentinel class %s', self::class),
        );
    }
}
