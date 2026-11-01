<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use function crc32;
use Closure;
use PhpParser\Error as ParseError;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Instruments PHP code so that it counts the edges between its basic blocks
 * in Edges.
 *
 * The code is parsed for the version of PHP that runs rattle, the places where
 * blocks start are found (see BlockFinder), and code that enters a block is
 * added at each of them (see BlockStart). The code is only ever added within
 * a line, so that every line of the original code keeps its number.
 *
 * The index of a block is derived from the path of the file and the offset at
 * which the block starts, so that a block has the same index in every worker,
 * however the files are included.
 *
 * When the constant operands of comparisons are collected, they are found in
 * the same traversal of the code (see ComparisonOperandFinder), which does
 * not change the code.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Instrumentor
{
    private Parser $parser;
    private ?ComparisonOperands $operands;

    /**
     * @param ?ComparisonOperands $operands collects the constant operands of the comparisons in the code that is instrumented, null to not look for them
     */
    public function __construct(?ComparisonOperands $operands = null)
    {
        $this->parser   = (new ParserFactory)->createForVersion(PhpVersion::getHostVersion());
        $this->operands = $operands;
    }

    /**
     * @param ?Closure(non-negative-int): positive-int $blockIndex returns the index of the block that starts at the offset, by default derived from the path and the offset
     *
     * @throws InstrumentationFailedException
     */
    public function instrument(string $code, string $path, ?Closure $blockIndex = null): string
    {
        $blockIndex ??= static fn (int $offset): int => self::blockIndex($path, $offset);

        try {
            $ast = $this->parser->parse($code);
        } catch (ParseError $e) {
            throw new InstrumentationFailedException($path, $e->getMessage(), $e);
        }

        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new InstrumentationFailedException($path, 'The code could not be parsed');
            // @codeCoverageIgnoreEnd
        }

        $finder = new BlockFinder($this->parser->getTokens());

        if ($this->operands === null) {
            (new NodeTraverser($finder))->traverse($ast);
        } else {
            $operandFinder = new ComparisonOperandFinder;

            (new NodeTraverser($finder, $operandFinder))->traverse($ast);

            $this->operands->add($operandFinder->operands());
        }

        $edits = new SourceEdits($code);

        foreach ($finder->blockStarts() as $blockStart) {
            $blockStart->addTo($edits, $blockIndex($blockStart->offset()));
        }

        return $edits->apply();
    }

    /**
     * A block index of 28 bits that is not 0, which stands for the entry
     * point.
     *
     * @return positive-int
     */
    private static function blockIndex(string $path, int $offset): int
    {
        $index = crc32($path . "\0" . $offset) & 0x0FFFFFFF;

        return $index === 0 ? 1 : $index;
    }
}
