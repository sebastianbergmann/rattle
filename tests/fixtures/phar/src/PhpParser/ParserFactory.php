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

/**
 * The factory of nikic/php-parser 4, which nikic/php-parser 5 replaced with
 * createForVersion() and createForNewestSupportedVersion().
 */
final class ParserFactory
{
    public const int PREFER_PHP7 = 1;

    public function create(int $kind): Parser
    {
        return new Parser;
    }
}
