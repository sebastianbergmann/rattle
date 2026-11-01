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

use function str_repeat;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComparisonOperandFinder::class)]
#[Small]
final class ComparisonOperandFinderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function code(): array
    {
        return [
            'identity and equality'                     => ['$a === "aaa"; "bbb" !== $a; $a == "ccc"; $a != "ddd";', ['aaa', 'bbb', 'ccc', 'ddd']],
            'order'                                     => ['$a < "aaa"; $a <= "bbb"; $a > "ccc"; $a >= "ddd"; $a <=> "eee";', ['aaa', 'bbb', 'ccc', 'ddd', 'eee']],
            'both sides'                                => ['"aaa" === "bbb";', ['aaa', 'bbb']],
            'operand that is not constant'              => ['$a === $b; $a === f("aaa"); $a === "aaa" . $b;', []],
            'other operator'                            => ['$a . "aaa"; $a = "bbb"; $a + 10;', []],
            'integers'                                  => ['$a > 1024; $a === -100; $a === -1024;', ['1024', '-100', '-1024']],
            'integers shorter than three characters'    => ['$a === 7; $a === 10; $a === -1;', []],
            'float'                                     => ['$a > 1.5;', []],
            'empty string'                              => ['$a === "";', []],
            'string of 2 bytes'                         => ['$a === "ab";', []],
            'string of 3 bytes'                         => ['$a === "abc";', ['abc']],
            'string of 64 bytes'                        => ['$a === "' . str_repeat('a', 64) . '";', [str_repeat('a', 64)]],
            'string of 65 bytes'                        => ['$a === "' . str_repeat('a', 65) . '";', []],
            'escape sequences'                          => ['$a === "\x89PNG\r\n";', ["\x89PNG\r\n"]],
            'the same operand twice'                    => ['$a === "aaa"; $b === "aaa";', ['aaa']],
            'conditions of the arms of a match'         => ['match ($a) { "aaa", "bbb" => "body", default => "default" };', ['aaa', 'bbb']],
            'cases of a switch'                         => ['switch ($a) { case "aaa": break; case 100: break; default: }', ['aaa', '100']],
            'function that compares strings'            => ['str_starts_with($a, "aaa"); strcmp("bbb", $a); explode("<br>", $a);', ['aaa', 'bbb', '<br>']],
            'function in uppercase'                     => ['STRPOS($a, "aaa");', ['aaa']],
            'fully qualified name of a function'        => ['\str_contains($a, "aaa");', ['aaa']],
            'named argument'                            => ['str_contains(needle: "aaa", haystack: $a);', ['aaa']],
            'namespaced function'                       => ['Foo\strpos($a, "aaa"); \Foo\strpos($a, "bbb"); namespace\strpos($a, "ccc");', []],
            'function that does not compare strings'    => ['strlen("aaa"); str_repeat("bbb", 10);', []],
            'dynamic function name'                     => ['$f($a, "aaa");', []],
            'method of the same name'                   => ['$o->strpos($a, "aaa"); Foo::strpos($a, "bbb");', []],
            'keys and values of an array'               => ['in_array($a, ["aaa", "bbb" => "ccc", 100 => 2, ...$d]);', ['aaa', 'bbb', 'ccc', '100']],
            'array that is compared'                    => ['$a === ["aaa"];', ['aaa']],
            'constant of the class'                     => ['class C { const A = "aaa"; function f($a) { return $a === self::A || $a === static::B || $a === C::C; } const B = "bbb"; const C = "ccc"; }', ['aaa', 'bbb', 'ccc']],
            'constant of another class of the file'     => ['namespace N; class C { const A = "aaa"; } function f($a) { return $a === \N\C::A; }', ['aaa']],
            'constant that is an array'                 => ['class C { const A = ["aaa" => 1]; function f($a) { return in_array($a, self::A); } }', ['aaa']],
            'constant that is not declared in the file' => ['class C { function f($a) { return $a === self::A || $a === D::B || $a === parent::C; } }', []],
            'constant whose value is not constant'      => ['class C { const A = "aaa" . "bbb"; function f($a) { return $a === self::A; } }', []],
            'self outside of a class'                   => ['$a === self::A;', []],
            'constant of an anonymous class'            => ['new class { const A = "aaa"; function f($a) { return $a === self::A; } };', ['aaa']],
            'constant of an enumeration'                => ['enum E: string { case X = "x"; const A = "aaa"; function f($a) { return $a === self::A; } }', ['aaa']],
            'class name that is dynamic'                => ['$a === $c::A; $a === C::$b;', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('code')]
    public function testFindsTheConstantOperandsOfComparisons(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->operandsOf('<?php ' . $code));
    }

    /**
     * @return list<non-empty-string>
     */
    private function operandsOf(string $code): array
    {
        $ast = (new ParserFactory)->createForHostVersion()->parse($code);

        $this->assertNotNull($ast);

        $finder = new ComparisonOperandFinder;

        (new NodeTraverser($finder))->traverse($ast);

        return $finder->operands();
    }
}
