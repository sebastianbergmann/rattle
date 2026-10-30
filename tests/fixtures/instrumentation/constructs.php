<?php declare(strict_types=1);
// This file is deliberately formatted in unusual ways. It is not touched by
// PHP-CS-Fixer, so that the instrumentation can be tested on code as it is
// written in the wild.
namespace SebastianBergmann\Rattle\Fixture\Instrumentation;

use Exception;
use LogicException;
use RuntimeException;

const LIMIT = 3;

interface Shape
{
    const SIDES = LIMIT ?? 4;

    public function area(): int;
}

enum Suit: string
{
    case Hearts = 'H';
    case Spades = 'S';

    public function color(): string
    {
        return match ($this) {
            self::Hearts => 'red',
            self::Spades => throw new LogicException('spades'),
        };
    }
}

final class Square implements Shape
{
    public int $side {
        get => $this->side ?? 0;
        set (int $value) {
            if ($value < 0) {
                throw new RuntimeException('negative side');
            }

            $this->side = $value;
        }
    }

    public ?Square $next = null;

    public function __construct(int $side, private readonly string $name = 'square' . LIMIT)
    {
        $this->side = $side;
    }

    public function area(): int { return $this->side * $this->side; }

    public function name(): string
    {
        // only a comment
        return $this->name;
    }

    public static function make(int $side = LIMIT * 2): self
    {
        static $made = 0;
        $made++;

        return new self($side);
    }
}

function empty_body()
{
    // only a comment
}

function empty_body_with_block_comment() { /* nothing */ }

function single_statement_bodies(int $x): string
{
    $result = '';

    if ($x > 0) $result .= 'positive';
    elseif ($x < 0) $result .= 'negative';
    else $result .= 'zero';

    if ($x > 1) if ($x > 2) $result .= ',big'; else $result .= ',two';

    for ($i = 0; $i < $x; $i++) $result .= '.';

    foreach ([1, 2] as $v) $result .= $v;

    while ($x-- > 5) $result .= '!';

    do $result .= '?'; while (false);

    if ($x > 100);

    return $result;
}

function alternative_syntax(array $values): string
{
    $result = '';

    if ($values === []): endif;

    foreach ($values as $value):
        if ($value % 2 === 0):
            $result .= 'even';
        elseif ($value % 3 === 0):
            $result .= 'three';
        else:
            $result .= 'odd';
        endif;
    endforeach;

    for ($i = 0; $i < 2; $i++): $result .= $i; endfor;

    while (false): endwhile;

    switch (count($values)):
        case 0;
        case 1:
            $result .= 'few';
            break;
        default:
            $result .= 'many';
    endswitch;

    return $result;
}

function short_circuits(?array $a, ?Square $s, string $member): string
{
    $x = $a['key'] ?? 'default';
    $y = isset($a['other']) && $a['other'] > 1 ? 'yes' : 'no';
    $z = $x ?: 'empty';
    $a['new'] ??= 'assigned';
    $w = ($y === 'yes' and $z !== '') or false;
    $n = $s?->next?->name();
    $m = $s?->$member;
    $k = $s?->{'side'};
    $o = isset($s?->next?->side);

    return $x . $y . $z . $a['new'] . var_export($w, true) . var_export($n, true) . var_export($m, true) . var_export($k, true) . var_export($o, true);
}

function generator(): \Generator
{
    $received = yield 1;
    yield from [2, 3];

    if ($received === 'stop') {
        return;
    }

    yield $received;
}

function closures(int $factor): array
{
    $double = fn (int $x): int => $x * $factor;
    $triple = static function (int $x) use ($factor): int {
        return $x * $factor * 3;
    };
    $nested = fn ($x) => fn ($y) => $x + $y;
    $callable = strlen(...);

    return array_map($double, [1, 2]) + [5 => $triple(1), 6 => $nested(1)(2), 7 => $callable('abc')];
}

function &reference_returning_arrow(array &$values): array
{
    $get = fn &(array &$v): array => $v;

    return $get($values);
}

function exceptions(int $mode): string
{
    try {
        if ($mode === 1) {
            throw new RuntimeException('mode 1');
        }

        return 'no exception';
    } catch (RuntimeException $e) {
        return 'caught ' . $e->getMessage();
    } finally {
        $mode = 0;
    }
}

function goto_label(int $n): int
{
    $i = 0;
    loop:
    $i++;
    if ($i < $n) goto loop;

    return $i;
}

function heredoc(string $name): string
{
    return <<<EOT
        Hello {$name},
        the answer is {$name}!
        EOT;
}

function inline_html(): string
{
    ob_start(); ?>
    <p>inline</p>
    <?php return trim(ob_get_clean());
}

function throws_in_condition(int $x): void
{
    if ($x > 0
        && throw_in_multi_line_expression($x)) {
        echo 'unreachable';
    }
}

function throw_in_multi_line_expression(int $x): bool
{
    $values = [
        'a' => 1,
        'b' => $x > 0
            ? throw new Exception('in multi-line expression')
            : 2,
    ];

    return $values['a'] === 1;
}

function throws_in_arrow_function(): array
{
    return array_map(
        fn (int $x): int => $x > 1 ? throw new LogicException('in arrow function') : $x,
        [1, 2, 3],
    );
}

function throws_in_closure_called_via_internal_function(): array
{
    return array_map(static function (int $x): int {
        if ($x === 2) {
            throw new RuntimeException('in closure');
        }

        return $x;
    }, [1, 2]);
}

function throws_in_generator(): array
{
    $generator = (static function () {
        yield 1;
        throw new RuntimeException('in generator');
    })();

    return iterator_to_array($generator);
}

function throws_in_match(int $x): string
{
    return match (true) {
        $x < 0 => 'negative',
        $x === 0 => 'zero',
        default => throw new LogicException('in match'),
    };
}

function throws_in_property_hook(): void
{
    $square = new Square(1);
    $square->side = -1;
}

function throws_in_enum_method(): string
{
    return Suit::Spades->color();
}

function throws_in_switch(int $x): void
{
    switch ($x) {
        case 1:
            throw new RuntimeException('in switch');
    }
}

function throws_in_nullsafe_chain(?Square $s): string
{
    return $s?->next
        ?->name() ?? throw new LogicException('in nullsafe chain');
}

function throws_after_loop(array $values): void
{
    foreach ($values as $value) {
        $value++;
    }

    throw new RuntimeException('after loop');
}

function warning_in_cast(string $digits): int
{
    return (int) (float) $digits;
}

function error_in_division(int $x): int
{
    return intdiv(1, $x);
}

function type_error(): int
{
    return strlen([]);
}
