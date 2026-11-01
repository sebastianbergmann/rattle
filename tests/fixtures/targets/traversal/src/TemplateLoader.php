<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\traversal;

use function file_get_contents;
use function is_file;
use function str_contains;

/**
 * Loads templates by name, without checking that the name stays in the
 * directory of the templates.
 */
final class TemplateLoader
{
    public function load(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            return '';
        }

        $file = __DIR__ . '/templates/' . $name;

        if (!is_file($file)) {
            return '';
        }

        return (string) file_get_contents($file);
    }
}
