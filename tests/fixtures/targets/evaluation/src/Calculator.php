<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\evaluation;

/**
 * Calculates the value of an arithmetic expression with eval(), without
 * checking that the expression is one.
 */
final class Calculator
{
    public function calculate(string $expression): mixed
    {
        return @eval('return ' . $expression . ';');
    }
}
