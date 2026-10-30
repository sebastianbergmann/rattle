<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\recursion;

use function explode;
use function preg_replace_callback;
use function str_contains;

/**
 * Expands variables in definitions such as "a=x;b=$a". A variable is
 * expanded to the expanded value of its definition. A definition that refers
 * to itself, directly or through other definitions, is expanded without end.
 */
final class TemplateExpander
{
    /**
     * @var array<string, string>
     */
    private array $definitions = [];

    /**
     * @return array<string, string>
     */
    public function expandAll(string $input): array
    {
        foreach (explode(';', $input) as $definition) {
            if (!str_contains($definition, '=')) {
                throw new TemplateException('Invalid definition');
            }

            [$name, $value] = explode('=', $definition, 2);

            $this->definitions[$name] = $value;
        }

        $expanded = [];

        foreach ($this->definitions as $name => $value) {
            $expanded[$name] = $this->expand($value);
        }

        return $expanded;
    }

    private function expand(string $value): string
    {
        return (string) preg_replace_callback(
            '/\$([a-z])/',
            fn (array $match): string => isset($this->definitions[$match[1]]) ? $this->expand($this->definitions[$match[1]]) : $match[0],
            $value,
        );
    }
}
