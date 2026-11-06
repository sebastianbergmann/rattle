--TEST--
rattle fuzz --phpunit with configurations that are not valid
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

use SebastianBergmann\Rattle\ArgumentsBuilder;
use SebastianBergmann\Rattle\FuzzCommand;

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-phpunit-invalid-configuration';

foreach ([
    ['--configuration', 'tests/fixtures/phpunit/phpunit.xml', '--filter', 'DoesNotMatchAnyProperty'],
    ['--configuration', 'tests/fixtures/phpunit/phpunit.xml', '--corpus', 'tests/fixtures/engine/corpus'],
    ['--configuration', 'tests/fixtures/phpunit/phpunit.xml', '--filter', 'testCountsAreNotNegative', '--corpus', '/does/not/exist'],
    ['--configuration', 'tests/fixtures/source-resolver/with-invalid-phpunit-xml/phpunit.xml'],
] as $arguments) {
    var_dump(
        (new FuzzCommand)->run(
            (new ArgumentsBuilder)->build(
                ['rattle', 'fuzz', '--phpunit', ...$arguments, '--jobs', '1', '--cache-directory', $directory . '/cache', '--findings-directory', $directory . '/findings'],
            ),
        ),
    );
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
The PHPUnit test suite has no properties that match the filter
int(2)
The options --corpus and --log-inputs can only be used with --phpunit when the filter selects one property
int(2)
The corpus directory "/does/not/exist" does not exist
int(2)
The worker could not be started: PHPUnit cannot be booted: %a
int(2)
