<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PhpParser;

use function explode;
use function intdiv;
use function strlen;

final class Parser
{
    /**
     * @return list<int|string>
     */
    public function parse(string $code): array
    {
        $statements = [];

        foreach (explode(';', $code) as $statement) {
            if ($statement === '') {
                continue;
            }

            if ($statement[0] === '<' && ($statement[1] ?? '') === '?') {
                // planted defect: an opening tag that is not followed by code
                $statements[] = intdiv(1, strlen($statement) - 2);
            }

            $statements[] = $statement;
        }

        return $statements;
    }
}
