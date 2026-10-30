<?php declare(strict_types=1);
// Runs the functions in constructs.php, with or without instrumentation, and
// prints what they returned or threw, and where, as JSON.
namespace SebastianBergmann\Rattle\Fixture\Instrumentation;

use SebastianBergmann\Rattle\Instrumentation\Edges;
use SebastianBergmann\Rattle\Instrumentation\IncludeHook;
use SebastianBergmann\Rattle\Instrumentation\Instrumentor;
use Throwable;

require __DIR__ . '/../../../vendor/autoload.php';

$instrumented = ($argv[1] ?? '') === 'instrumented';

if ($instrumented) {
    $hook = new IncludeHook(new Instrumentor, [__DIR__ . '/constructs.php'], []);
    $hook->register();
}

$issues = [];

set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$issues): bool
{
    $issues[] = [$type, $message, $file, $line];

    return true;
});

require __DIR__ . '/constructs.php';

if ($instrumented) {
    $hook->unregister();
}

$calls = [
    'empty_body'                                   => static fn () => empty_body(),
    'empty_body_with_block_comment'                => static fn () => empty_body_with_block_comment(),
    'single_statement_bodies(7)'                   => static fn () => single_statement_bodies(7),
    'single_statement_bodies(2)'                   => static fn () => single_statement_bodies(2),
    'single_statement_bodies(0)'                   => static fn () => single_statement_bodies(0),
    'single_statement_bodies(-1)'                  => static fn () => single_statement_bodies(-1),
    'alternative_syntax([])'                       => static fn () => alternative_syntax([]),
    'alternative_syntax([1])'                      => static fn () => alternative_syntax([1]),
    'alternative_syntax([1, 2, 3, 4])'             => static fn () => alternative_syntax([1, 2, 3, 4]),
    'short_circuits(null, null)'                   => static fn () => short_circuits(null, null, 'side'),
    'short_circuits(array, square)'                => static fn () => short_circuits(['key' => '', 'other' => 2], new Square(2), 'side'),
    'generator'                                    => static function () {
        $generator = generator();
        $values    = [$generator->current()];

        $generator->send('go');

        while ($generator->valid()) {
            $values[] = $generator->current();

            $generator->next();
        }

        return $values;
    },
    'closures'                                     => static fn () => closures(2),
    'reference_returning_arrow'                    => static function () {
        $values    = [1];
        $reference = &reference_returning_arrow($values);
        $reference[] = 2;

        return $values;
    },
    'exceptions(0)'                                => static fn () => exceptions(0),
    'exceptions(1)'                                => static fn () => exceptions(1),
    'goto_label'                                   => static fn () => goto_label(3),
    'heredoc'                                      => static fn () => heredoc('rattle'),
    'inline_html'                                  => static fn () => inline_html(),
    'square'                                       => static fn () => [Square::make()->area(), Square::make(3)->name(), Square::SIDES],
    'suit'                                         => static fn () => Suit::Hearts->color(),
    'throws_in_condition'                          => static fn () => throws_in_condition(1),
    'throws_in_arrow_function'                     => static fn () => throws_in_arrow_function(),
    'throws_in_closure_called_via_internal_function' => static fn () => throws_in_closure_called_via_internal_function(),
    'throws_in_generator'                          => static fn () => throws_in_generator(),
    'throws_in_match'                              => static fn () => throws_in_match(1),
    'throws_in_property_hook'                      => static fn () => throws_in_property_hook(),
    'throws_in_enum_method'                        => static fn () => throws_in_enum_method(),
    'throws_in_switch'                             => static fn () => throws_in_switch(1),
    'throws_in_nullsafe_chain'                     => static fn () => throws_in_nullsafe_chain(null),
    'throws_after_loop'                            => static fn () => throws_after_loop([1, 2]),
    'warning_in_cast'                              => static fn () => warning_in_cast('99999999999999999999'),
    'error_in_division'                            => static fn () => error_in_division(0),
    'type_error'                                   => static fn () => type_error(),
];

$results = [];
$edges   = 0;

foreach ($calls as $name => $call) {
    $issues = [];

    Edges::reset();

    try {
        $result = ['returned' => var_export($call(), true)];
    } catch (Throwable $t) {
        $result = [
            'threw'   => $t::class,
            'message' => $t->getMessage(),
            'file'    => $t->getFile(),
            'line'    => $t->getLine(),
            'trace'   => array_map(
                static fn (array $frame): string => ($frame['file'] ?? '') . ':' . ($frame['line'] ?? ''),
                array_filter(
                    $t->getTrace(),
                    static fn (array $frame): bool => ($frame['file'] ?? '') === __DIR__ . '/constructs.php',
                ),
            ),
        ];
    }

    $result['issues'] = $issues;
    $results[$name]   = $result;
    $edges += count(Edges::$hits);
}

print json_encode(['results' => $results, 'edges' => $edges], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
