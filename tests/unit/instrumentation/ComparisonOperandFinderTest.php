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
            'identity and equality'                     => ['$a === "a"; "b" !== $a; $a == "c"; $a != "d";', ['a', 'b', 'c', 'd']],
            'order'                                     => ['$a < "a"; $a <= "b"; $a > "c"; $a >= "d"; $a <=> "e";', ['a', 'b', 'c', 'd', 'e']],
            'both sides'                                => ['"a" === "b";', ['a', 'b']],
            'operand that is not constant'              => ['$a === $b; $a === f("a"); $a === "a" . $b;', []],
            'other operator'                            => ['$a . "a"; $a = "b"; $a + 10;', []],
            'integers'                                  => ['$a > 1024; $a === -1; $a === -1024;', ['1024', '-1', '-1024']],
            'integer with a single digit'               => ['$a === 7;', []],
            'float'                                     => ['$a > 1.5;', []],
            'empty string'                              => ['$a === "";', []],
            'string of 64 bytes'                        => ['$a === "' . str_repeat('a', 64) . '";', [str_repeat('a', 64)]],
            'string of 65 bytes'                        => ['$a === "' . str_repeat('a', 65) . '";', []],
            'escape sequences'                          => ['$a === "\x89PNG\r\n";', ["\x89PNG\r\n"]],
            'the same operand twice'                    => ['$a === "a"; $b === "a";', ['a']],
            'conditions of the arms of a match'         => ['match ($a) { "a", "b" => "body", default => "default" };', ['a', 'b']],
            'cases of a switch'                         => ['switch ($a) { case "a": break; case 10: break; default: }', ['a', '10']],
            'function that compares strings'            => ['str_starts_with($a, "a"); strcmp("b", $a); explode(",", $a);', ['a', 'b', ',']],
            'function in uppercase'                     => ['STRPOS($a, "a");', ['a']],
            'fully qualified name of a function'        => ['\str_contains($a, "a");', ['a']],
            'named argument'                            => ['str_contains(needle: "a", haystack: $a);', ['a']],
            'namespaced function'                       => ['Foo\strpos($a, "a"); \Foo\strpos($a, "b"); namespace\strpos($a, "c");', []],
            'function that does not compare strings'    => ['strlen("a"); str_repeat("b", 10);', []],
            'dynamic function name'                     => ['$f($a, "a");', []],
            'method of the same name'                   => ['$o->strpos($a, "a"); Foo::strpos($a, "b");', []],
            'keys and values of an array'               => ['in_array($a, ["a", "b" => "c", 10 => 2, ...$d]);', ['a', 'b', 'c', '10']],
            'array that is compared'                    => ['$a === ["a"];', ['a']],
            'constant of the class'                     => ['class C { const A = "a"; function f($a) { return $a === self::A || $a === static::B || $a === C::C; } const B = "b"; const C = "c"; }', ['a', 'b', 'c']],
            'constant of another class of the file'     => ['namespace N; class C { const A = "a"; } function f($a) { return $a === \N\C::A; }', ['a']],
            'constant that is an array'                 => ['class C { const A = ["a" => 1]; function f($a) { return in_array($a, self::A); } }', ['a']],
            'constant that is not declared in the file' => ['class C { function f($a) { return $a === self::A || $a === D::B || $a === parent::C; } }', []],
            'constant whose value is not constant'      => ['class C { const A = "a" . "b"; function f($a) { return $a === self::A; } }', []],
            'self outside of a class'                   => ['$a === self::A;', []],
            'constant of an anonymous class'            => ['new class { const A = "a"; function f($a) { return $a === self::A; } };', ['a']],
            'constant of an enumeration'                => ['enum E: string { case X = "x"; const A = "a"; function f($a) { return $a === self::A; } }', ['a']],
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
