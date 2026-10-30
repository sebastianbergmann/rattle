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

use function assert;
use function strlen;
use function var_export;

/**
 * A place in the source where a basic block starts, and how code that enters
 * the block is added there without changing the line of any original code.
 *
 * Where a statement may stand, a statement is inserted. Where it may not,
 * the expression that starts the block is passed through a call. Edits where
 * nodes start are ordered from the outermost node to the innermost one,
 * edits where nodes end from the innermost node to the outermost one, so
 * that nested constructs that start or end at the same offset are closed in
 * the right order.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class BlockStart
{
    private const int BEFORE_STATEMENT      = 0;
    private const int AFTER_STATEMENT       = 1;
    private const int AROUND_STATEMENT      = 2;
    private const int FOR_EMPTY_STATEMENT   = 3;
    private const int AROUND_EXPRESSION     = 4;
    private const int FOR_MEMBER_NAME       = 5;
    private const int AROUND_MEMBER_NAME    = 6;
    private const int IN_BRACED_MEMBER_NAME = 7;

    /**
     * Edits where nodes end come before edits where nodes start.
     */
    private const int END = -1_000_000;

    /**
     * @var self::AFTER_STATEMENT|self::AROUND_EXPRESSION|self::AROUND_MEMBER_NAME|self::AROUND_STATEMENT|self::BEFORE_STATEMENT|self::FOR_EMPTY_STATEMENT|self::FOR_MEMBER_NAME|self::IN_BRACED_MEMBER_NAME
     */
    private int $how;

    /**
     * @var non-negative-int
     */
    private int $start;

    /**
     * @var non-negative-int
     */
    private int $end;
    private int $depth;
    private string $name;

    /**
     * A statement is inserted at the offset, where a body starts.
     *
     * @param non-negative-int $offset
     */
    public static function beforeStatement(int $offset, int $depth): self
    {
        return new self(self::BEFORE_STATEMENT, $offset, $offset, $depth, '');
    }

    /**
     * A statement is inserted after the statement that ends at the offset.
     *
     * @param non-negative-int $end the offset of the last character of the statement
     */
    public static function afterStatement(int $end, int $depth): self
    {
        return new self(self::AFTER_STATEMENT, $end + 1, $end + 1, $depth, '');
    }

    /**
     * The statement that is the body of a branch or a loop on its own is
     * enclosed in braces, together with the statement that is inserted.
     *
     * @param non-negative-int $start
     * @param non-negative-int $end   the offset of the last character of the statement
     */
    public static function aroundStatement(int $start, int $end, int $depth): self
    {
        return new self(self::AROUND_STATEMENT, $start, $end + 1, $depth, '');
    }

    /**
     * The empty statement that is the body of a branch or a loop is replaced
     * with braces that enclose the statement that is inserted.
     *
     * @param non-negative-int $offset the offset of the semicolon
     */
    public static function forEmptyStatement(int $offset, int $depth): self
    {
        return new self(self::FOR_EMPTY_STATEMENT, $offset, $offset + 1, $depth, '');
    }

    /**
     * @param non-negative-int $start
     * @param non-negative-int $end   the offset of the last character of the expression
     */
    public static function aroundExpression(int $start, int $end, int $depth): self
    {
        return new self(self::AROUND_EXPRESSION, $start, $end + 1, $depth, '');
    }

    /**
     * The name of a member that follows a nullsafe operator is replaced with
     * an expression in braces that evaluates to the name: $a?->b becomes
     * $a?->{call('b')}, so that the block is only entered when $a is not
     * null.
     *
     * @param non-negative-int $start
     * @param non-empty-string $name
     */
    public static function forMemberName(int $start, string $name, int $depth): self
    {
        return new self(self::FOR_MEMBER_NAME, $start, $start + strlen($name), $depth, $name);
    }

    /**
     * The expression that is the name of a member that follows a nullsafe
     * operator is passed through a call, and enclosed in braces unless it is
     * already: $a?->$b becomes $a?->{call($b)}.
     *
     * @param non-negative-int $start
     * @param non-negative-int $end   the offset of the last character of the expression
     */
    public static function aroundMemberName(int $start, int $end, bool $braced, int $depth): self
    {
        return new self($braced ? self::IN_BRACED_MEMBER_NAME : self::AROUND_MEMBER_NAME, $start, $end + 1, $depth, '');
    }

    /**
     * @param self::AFTER_STATEMENT|self::AROUND_EXPRESSION|self::AROUND_MEMBER_NAME|self::AROUND_STATEMENT|self::BEFORE_STATEMENT|self::FOR_EMPTY_STATEMENT|self::FOR_MEMBER_NAME|self::IN_BRACED_MEMBER_NAME $how
     * @param non-negative-int                                                                                                                                                                                 $start
     * @param non-negative-int                                                                                                                                                                                 $end
     */
    private function __construct(int $how, int $start, int $end, int $depth, string $name)
    {
        $this->how   = $how;
        $this->start = $start;
        $this->end   = $end;
        $this->depth = $depth;
        $this->name  = $name;
    }

    /**
     * The offset at which the block starts, from which its index is derived.
     *
     * @return non-negative-int
     */
    public function offset(): int
    {
        return $this->start;
    }

    /**
     * @param positive-int $block
     */
    public function addTo(SourceEdits $edits, int $block): void
    {
        $statement = Edges::statementFor($block);
        $call      = Edges::callFor($block);
        $opening   = 2 * $this->depth;
        $closing   = self::END - 2 * $this->depth;

        switch ($this->how) {
            case self::BEFORE_STATEMENT:
                $edits->insert($this->start, ' ' . $statement, $opening);

                break;

            case self::AFTER_STATEMENT:
                // after the braces that close the nodes that end here
                $edits->insert($this->start, ' ' . $statement, $closing + 1);

                break;

            case self::AROUND_STATEMENT:
                $edits->insert($this->start, '{ ' . $statement . ' ', $opening);
                $edits->insert($this->end, ' }', $closing);

                break;

            case self::FOR_EMPTY_STATEMENT:
                $edits->replace($this->start, 1, '{ ' . $statement . ' }');

                break;

            case self::AROUND_EXPRESSION:
            case self::IN_BRACED_MEMBER_NAME:
                $edits->insert($this->start, $call, $opening);
                $edits->insert($this->end, ')', $closing);

                break;

            case self::FOR_MEMBER_NAME:
                $length = $this->end - $this->start;

                assert($length > 0);

                $edits->replace($this->start, $length, '{' . $call . var_export($this->name, true) . ')}');

                break;

            case self::AROUND_MEMBER_NAME:
                $edits->insert($this->start, '{' . $call, $opening);
                $edits->insert($this->end, ')}', $closing);

                break;
        }
    }
}
