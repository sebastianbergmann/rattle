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
use function count;
use function is_array;
use function ord;
use function strlen;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

/**
 * Finds the places where basic blocks start:
 *
 * - at the start of the body of a function, a method, a closure, a property
 *   hook, a catch, and a finally
 * - at the start of each body of a conditional or a loop, and of each case of
 *   a switch, and after the conditional, the loop, the switch, and the try
 *   statement as a whole, and after a label
 * - within expressions: at the operand of a short-circuiting operator, the
 *   alternative of a ternary, and the right side of ??= that may not be
 *   evaluated, at the body of an arrow function and of a match arm, after a
 *   yield, and at the member name after a nullsafe operator
 *
 * The bodies of conditionals and loops are recognised by the token after
 * their header: an opening brace, a colon for the alternative syntax, a
 * semicolon for an empty statement, or else the single statement that is the
 * body.
 *
 * Expressions that must be constant, such as default values of parameters and
 * properties, constants, case values of enumerations, initializers of static
 * variables, attribute arguments, and values in declare, are skipped, as are
 * arrow functions that return by reference, whose body must not be wrapped in
 * a call.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class BlockFinder extends NodeVisitorAbstract
{
    private const string CONSTANT = 'rattleConstantExpression';

    /**
     * @var array<Token>
     */
    private readonly array $tokens;

    /**
     * @var list<BlockStart>
     */
    private array $blockStarts = [];
    private int $depth         = 0;

    /**
     * How many constant expressions enclose the current node.
     */
    private int $constant = 0;

    /**
     * @param array<Token> $tokens the tokens of the code that was parsed
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @return list<BlockStart>
     */
    public function blockStarts(): array
    {
        return $this->blockStarts;
    }

    public function enterNode(Node $node): null
    {
        $this->depth++;

        foreach ($this->constantExpressionsOf($node) as $expression) {
            $expression->setAttribute(self::CONSTANT, true);
        }

        if ($node->getAttribute(self::CONSTANT) === true) {
            $this->constant++;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($this->constant === 0) {
            $this->find($node);
        }

        if ($node->getAttribute(self::CONSTANT) === true) {
            $this->constant--;
        }

        $this->depth--;

        return null;
    }

    private function find(Node $node): void
    {
        if ($node instanceof Stmt\Function_ ||
            $node instanceof Stmt\ClassMethod ||
            $node instanceof Expr\Closure ||
            $node instanceof Stmt\Catch_ ||
            $node instanceof Stmt\Finally_) {
            if ($node->stmts !== null) {
                $this->bracedBody($node, $node->stmts);
            }

            return;
        }

        if ($node instanceof Node\PropertyHook) {
            if (is_array($node->body)) {
                $this->bracedBody($node, $node->body);
            } elseif ($node->body !== null) {
                $this->expression($node->body);
            }

            return;
        }

        if ($node instanceof Stmt\If_ ||
            $node instanceof Stmt\While_ ||
            $node instanceof Stmt\For_ ||
            $node instanceof Stmt\Foreach_) {
            $this->body($node->stmts, $this->tokenAfterHeader($node));
            $this->after($node);

            return;
        }

        if ($node instanceof Stmt\ElseIf_) {
            $this->body($node->stmts, $this->tokenAfterHeader($node));

            return;
        }

        if ($node instanceof Stmt\Else_) {
            $this->body($node->stmts, $this->significantTokenAfter($node->getStartTokenPos()));

            return;
        }

        if ($node instanceof Stmt\Do_) {
            $this->body($node->stmts, $this->significantTokenAfter($node->getStartTokenPos()));
            $this->after($node);

            return;
        }

        if ($node instanceof Stmt\Case_) {
            // the colon or semicolon after the value of the case, or after
            // the keyword default; the parentheses around a value are not
            // part of its node
            $index = $this->significantTokenAfter($node->cond !== null ? $node->cond->getEndTokenPos() : $node->getStartTokenPos());

            while ($this->token($index)->text === ')') {
                $index = $this->significantTokenAfter($index);
            }

            $this->afterToken($index);

            return;
        }

        if ($node instanceof Stmt\Switch_ ||
            $node instanceof Stmt\TryCatch ||
            $node instanceof Stmt\Label) {
            $this->after($node);

            return;
        }

        if ($node instanceof Expr\BinaryOp\BooleanAnd ||
            $node instanceof Expr\BinaryOp\BooleanOr ||
            $node instanceof Expr\BinaryOp\LogicalAnd ||
            $node instanceof Expr\BinaryOp\LogicalOr ||
            $node instanceof Expr\BinaryOp\Coalesce) {
            $this->expression($node->right);

            return;
        }

        if ($node instanceof Expr\AssignOp\Coalesce) {
            $this->expression($node->expr);

            return;
        }

        if ($node instanceof Expr\Ternary) {
            $this->expression($node->else);

            return;
        }

        if ($node instanceof Expr\ArrowFunction) {
            if (!$node->byRef) {
                $this->expression($node->expr);
            }

            return;
        }

        if ($node instanceof Node\MatchArm) {
            $this->expression($node->body);

            return;
        }

        if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
            // the block starts when the generator is resumed
            $this->expression($node);

            return;
        }

        if ($node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\NullsafePropertyFetch) {
            $this->memberName($node->name);
        }
    }

    /**
     * A body that is always enclosed in braces. The statement is inserted
     * after the opening brace, which is found by going back from the first
     * statement over whitespace, comments, and inline HTML, or before the
     * closing brace of an empty body.
     *
     * @param array<Node> $stmts
     */
    private function bracedBody(Node $node, array $stmts): void
    {
        foreach ($stmts as $first) {
            $index = $first->getStartTokenPos();

            do {
                $index--;
            } while ($this->token($index)->id !== ord('{'));

            $this->afterToken($index);

            return;
        }

        $closing = $this->token($node->getEndTokenPos());

        assert($closing->text === '}');

        $this->blockStarts[] = BlockStart::beforeStatement($this->offsetOf($closing), $this->depth);
    }

    /**
     * The body of a conditional or a loop, which starts with the token after
     * its header.
     *
     * @param array<Node> $stmts
     */
    private function body(array $stmts, int $token): void
    {
        $text = $this->token($token)->text;

        if ($text === '{' || $text === ':') {
            $this->afterToken($token);

            return;
        }

        if ($text === ';' && $stmts === []) {
            $this->blockStarts[] = BlockStart::forEmptyStatement($this->offsetOf($this->token($token)), $this->depth);

            return;
        }

        assert(count($stmts) === 1);

        foreach ($stmts as $statement) {
            $this->blockStarts[] = BlockStart::aroundStatement($this->startOf($statement), $this->endOf($statement), $this->depth);
        }
    }

    private function afterToken(int $index): void
    {
        $token  = $this->token($index);
        $offset = $this->offsetOf($token) + strlen($token->text);

        $this->blockStarts[] = BlockStart::beforeStatement($offset, $this->depth);
    }

    private function after(Stmt $statement): void
    {
        $this->blockStarts[] = BlockStart::afterStatement($this->endOf($statement), $this->depth);
    }

    private function expression(Expr $expression): void
    {
        $this->blockStarts[] = BlockStart::aroundExpression($this->startOf($expression), $this->endOf($expression), $this->depth);
    }

    private function memberName(Expr|Node\Identifier $name): void
    {
        if ($name instanceof Node\Identifier) {
            $this->blockStarts[] = BlockStart::forMemberName($this->startOf($name), $name->toString(), $this->depth);

            return;
        }

        $braced = $this->token($this->significantTokenBefore($name->getStartTokenPos()))->text === '{';

        $this->blockStarts[] = BlockStart::aroundMemberName($this->startOf($name), $this->endOf($name), $braced, $this->depth);
    }

    /**
     * The token after the parentheses that follow the keyword a conditional
     * or a loop starts with.
     */
    private function tokenAfterHeader(Node $node): int
    {
        $index = $this->significantTokenAfter($node->getStartTokenPos());

        assert($this->token($index)->text === '(');

        for ($open = 1; $open > 0;) {
            $text = $this->token(++$index)->text;

            if ($text === '(') {
                $open++;
            } elseif ($text === ')') {
                $open--;
            }
        }

        return $this->significantTokenAfter($index);
    }

    private function significantTokenAfter(int $index): int
    {
        while ($this->token(++$index)->isIgnorable()) {
        }

        return $index;
    }

    private function significantTokenBefore(int $index): int
    {
        while ($this->token(--$index)->isIgnorable()) {
        }

        return $index;
    }

    /**
     * @return list<Node>
     */
    private function constantExpressionsOf(Node $node): array
    {
        $candidates = match (true) {
            $node instanceof Node\Param,
            $node instanceof Node\PropertyItem,
            $node instanceof Stmt\StaticVar => [$node->default],
            $node instanceof Node\Const_,
            $node instanceof Node\DeclareItem => [$node->value],
            $node instanceof Stmt\EnumCase    => [$node->expr],
            $node instanceof Node\Attribute   => [$node],
            default                           => [],
        };

        $expressions = [];

        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                $expressions[] = $candidate;
            }
        }

        return $expressions;
    }

    private function token(int $index): Token
    {
        assert(isset($this->tokens[$index]));

        return $this->tokens[$index];
    }

    /**
     * @return non-negative-int
     */
    private function offsetOf(Token $token): int
    {
        assert($token->pos >= 0);

        return $token->pos;
    }

    /**
     * @return non-negative-int
     */
    private function startOf(Node $node): int
    {
        $offset = $node->getStartFilePos();

        assert($offset >= 0);

        return $offset;
    }

    /**
     * @return non-negative-int
     */
    private function endOf(Node $node): int
    {
        $offset = $node->getEndFilePos();

        assert($offset >= 0);

        return $offset;
    }
}
