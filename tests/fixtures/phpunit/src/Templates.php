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

use function file_get_contents;
use function str_contains;

final class Templates
{
    public function load(string $name): string
    {
        if (str_contains($name, "\0")) {
            return '';
        }

        $template = @file_get_contents(__DIR__ . '/templates/' . $name);

        return $template === false ? '' : $template;
    }
}
