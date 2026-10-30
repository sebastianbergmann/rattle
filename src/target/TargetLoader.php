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

use function get_debug_type;
use function is_file;
use function sprintf;

/**
 * Loads a target file, which returns a target.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class TargetLoader
{
    /**
     * @throws InvalidTargetException
     */
    public function load(string $path): Target
    {
        if (!is_file($path)) {
            throw new InvalidTargetException(sprintf('The target file "%s" does not exist', $path));
        }

        $target = (static function (string $__path): mixed
        {
            return require $__path;
        })($path);

        if (!$target instanceof Target) {
            throw new InvalidTargetException(
                sprintf(
                    'The target file "%s" must return an instance of %s, %s returned',
                    $path,
                    Target::class,
                    get_debug_type($target),
                ),
            );
        }

        return $target;
    }
}
