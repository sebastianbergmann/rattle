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

use function count;
use function explode;
use function file_get_contents;
use function preg_quote;
use function preg_replace;
use function strlen;
use function strpos;
use function substr_count;
use Closure;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Instrumentor::class)]
#[CoversClass(BlockFinder::class)]
#[CoversClass(BlockStart::class)]
#[CoversClass(SourceEdits::class)]
#[CoversClass(InstrumentationFailedException::class)]
#[CoversClass(Edges::class)]
#[CoversClass(ComparisonOperandFinder::class)]
#[CoversClass(ComparisonOperands::class)]
#[Small]
final class InstrumentorTest extends TestCase
{
    /**
     * The code that is expected is abbreviated: [n] stands for the statement
     * that enters the block n, and Tn( for the call that enters the block n
     * within an expression.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        return [
            'function body' => [
                '<?php function f() { a(); }',
                '<?php function f() { [1] a(); }',
            ],
            'empty function body' => [
                '<?php function f() {}',
                '<?php function f() { [1]}',
            ],
            'function body that only contains a comment' => [
                '<?php function f() {
    // comment
}',
                '<?php function f() { [1]
    // comment
}',
            ],
            'function body that starts with inline HTML' => [
                '<?php function f() { ?>html<?php }',
                '<?php function f() { [1] ?>html<?php }',
            ],
            'abstract method' => [
                '<?php abstract class C { abstract function m(); }',
                '<?php abstract class C { abstract function m(); }',
            ],
            'method and closure' => [
                '<?php class C { function m() { return function () { a(); }; } }',
                '<?php class C { function m() { [2] return function () { [1] a(); }; } }',
            ],
            'if, elseif, and else in braces' => [
                '<?php if ($a) { a(); } elseif ($b) { b(); } else { c(); }',
                '<?php if ($a) { [3] a(); } elseif ($b) { [1] b(); } else { [2] c(); } [4]',
            ],
            'if, elseif, and else with single statements' => [
                '<?php if ($a) a(); elseif ($b) b(); else c();',
                '<?php if ($a) { [3] a(); } elseif ($b) { [1] b(); } else { [2] c(); } [4]',
            ],
            'nested if statements without braces' => [
                '<?php if ($a) if ($b) a();',
                '<?php if ($a) { [3] if ($b) { [1] a(); } [2] } [4]',
            ],
            'if statement with an empty statement' => [
                '<?php if ($a);',
                '<?php if ($a){ [1] } [2]',
            ],
            'if statement in alternative syntax' => [
                '<?php if ($a): a(); else: b(); endif;',
                '<?php if ($a): [2] a(); else: [1] b(); endif; [3]',
            ],
            'empty if statement in alternative syntax' => [
                '<?php if ($a): endif;',
                '<?php if ($a): [1] endif; [2]',
            ],
            'loops' => [
                '<?php for (;;) a(); foreach ($a as $b) {} while ($c): endwhile; do b(); while ($d);',
                '<?php for (;;) { [1] a(); } [2] foreach ($a as $b) { [3]} [4] while ($c): [5] endwhile; [6] do { [7] b(); } while ($d); [8]',
            ],
            'loop with parentheses in its condition' => [
                '<?php while (f(g($a), (1))) a();',
                '<?php while (f(g($a), (1))) { [1] a(); } [2]',
            ],
            'switch' => [
                '<?php switch ($a) { case 1; case f(1): a(); default: }',
                '<?php switch ($a) { case 1; [1] case f(1): [2] a(); default: [3] } [4]',
            ],
            'switch with the values of its cases in parentheses' => [
                '<?php switch ($a) { case (1): a(); case ((B)); case (f(1)) : }',
                '<?php switch ($a) { case (1): [1] a(); case ((B)); [2] case (f(1)) : [3] } [4]',
            ],
            'try, catch, and finally' => [
                '<?php try { a(); } catch (E $e) { b(); } finally { c(); }',
                '<?php try { a(); } catch (E $e) { [1] b(); } finally { [2] c(); } [3]',
            ],
            'label' => [
                '<?php l: a(); goto l;',
                '<?php l: [1] a(); goto l;',
            ],
            'short-circuiting operators' => [
                '<?php $a && $b; $a || $b; $a and $b; $a or $b; $a ?? $b; $a ? $b : $c; $a ?: $c; $a ??= $b;',
                '<?php $a && T1($b); $a || T2($b); $a and T3($b); $a or T4($b); $a ?? T5($b); $a ? $b : T6($c); $a ?: T7($c); $a ??= T8($b);',
            ],
            'arrow functions' => [
                '<?php fn () => $a ?? $b; fn &() => $a;',
                '<?php fn () => T2($a ?? T1($b)); fn &() => $a;',
            ],
            'match' => [
                '<?php match ($a) { 1 => a(), default => throw new E };',
                '<?php match ($a) { 1 => T1(a()), default => T2(throw new E) };',
            ],
            'yield' => [
                '<?php function g() { $a = yield; yield from $b; }',
                '<?php function g() { [3] $a = T1(yield); T2(yield from $b); }',
            ],
            'nullsafe operators' => [
                '<?php $a?->b()?->c; $a?->$b; $a?->{$b}();',
                '<?php $a?->{T1(\'b\')}()?->{T2(\'c\')}; $a?->{T3($b)}; $a?->{T4($b)}();',
            ],
            'property hooks' => [
                '<?php class C { public $p { get => $this->p; set { $this->p = $value; } } public $q { get; } }',
                '<?php class C { public $p { get => T1($this->p); set { [2] $this->p = $value; } } public $q { get; } }',
            ],
            'constant expressions' => [
                '<?php const A = B ?? C; class C { const D = E ?: F; public $p = G ?? H; function m($a = I && J) { static $s = K ?? L; } } enum E: int { case A = 1 ?? 2; } #[Attr(A ?? B)] function f() {} declare(ticks=A ?? 1);',
                '<?php const A = B ?? C; class C { const D = E ?: F; public $p = G ?? H; function m($a = I && J) { [1] static $s = K ?? L; } } enum E: int { case A = 1 ?? 2; } #[Attr(A ?? B)] function f() { [2]} declare(ticks=A ?? 1);',
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testInstrumentsCode(string $code, string $expected): void
    {
        $this->assertSame($expected, $this->abbreviate((new Instrumentor)->instrument($code, 'test.php', $this->counter())));
    }

    #[DataProvider('provider')]
    public function testInstrumentedCodeCanBeParsedAndHasTheSameNumberOfLines(string $code, string $expected): void
    {
        $instrumented = (new Instrumentor)->instrument($code, 'test.php', $this->counter());

        (new ParserFactory)->createForHostVersion()->parse($instrumented);

        $this->assertSame(substr_count($code, "\n"), substr_count($instrumented, "\n"));
    }

    public function testEveryLineKeepsItsNumberAndContent(): void
    {
        $code         = (string) file_get_contents(__DIR__ . '/../../fixtures/instrumentation/constructs.php');
        $instrumented = (new Instrumentor)->instrument($code, 'constructs.php');

        $originalLines     = explode("\n", $code);
        $instrumentedLines = explode("\n", $instrumented);

        $this->assertCount(count($originalLines), $instrumentedLines);

        foreach ($originalLines as $number => $line) {
            $this->assertTrue(
                $this->isSubsequence($line, $instrumentedLines[$number] ?? ''),
                'Line ' . ($number + 1) . ' is not kept',
            );
        }
    }

    public function testDerivesBlockIndexesFromThePathAndTheOffset(): void
    {
        $code = '<?php function f() { a(); }';

        $first  = (new Instrumentor)->instrument($code, '/path/to/a.php');
        $second = (new Instrumentor)->instrument($code, '/path/to/a.php');
        $third  = (new Instrumentor)->instrument($code, '/path/to/b.php');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $third);
        $this->assertStringContainsString('\\' . Edges::class . '::$previous = ', $first);
    }

    public function testCollectsTheConstantOperandsOfComparisonsWithoutChangingTheInstrumentedCode(): void
    {
        $code     = '<?php function f($a) { if ($a === "aaa") { return str_starts_with($a, "bbb"); } }';
        $operands = new ComparisonOperands;

        $this->assertSame(
            (new Instrumentor)->instrument($code, 'a.php'),
            (new Instrumentor($operands))->instrument($code, 'a.php'),
        );

        (new Instrumentor($operands))->instrument('<?php $a === "ccc";', 'b.php');

        $this->assertSame(['aaa', 'bbb', 'ccc'], $operands->takeUnreported());
    }

    public function testCannotInstrumentCodeThatCannotBeParsed(): void
    {
        $this->expectException(InstrumentationFailedException::class);
        $this->expectExceptionMessageIsOrContains('Cannot instrument broken.php: Syntax error');

        (new Instrumentor)->instrument('<?php function (', 'broken.php');
    }

    /**
     * Whether the characters of the original line occur in the instrumented
     * line in the same order: code is only added, and an empty statement or a
     * member name that is replaced keeps its characters.
     */
    private function isSubsequence(string $original, string $instrumented): bool
    {
        $offset = 0;

        for ($i = 0; $i < strlen($original); $i++) {
            $offset = strpos($instrumented, $original[$i], $offset);

            if ($offset === false) {
                return false;
            }

            $offset++;
        }

        return true;
    }

    /**
     * @return Closure(non-negative-int): positive-int
     */
    private function counter(): Closure
    {
        $index = 0;

        return static function () use (&$index): int
        {
            return ++$index;
        };
    }

    private function abbreviate(string $code): string
    {
        $edges = preg_quote('\\' . Edges::class, '/');
        $edge  = $edges . '::\$hits\[' . $edges . '::\$previous << 28 \| (\d+)\]';

        $code = (string) preg_replace(
            '/isset\(' . $edge . '\) \? \+\+' . $edges . '::\$hits\[' . $edges . '::\$previous << 28 \| \1\] : \(' . $edges . '::\$hits\[' . $edges . '::\$previous << 28 \| \1\] = 1\); ' . $edges . '::\$previous = \1;/',
            '[$1]',
            $code,
        );

        return (string) preg_replace('/' . $edges . '::through\((\d+), /', 'T$1(', $code);
    }
}
