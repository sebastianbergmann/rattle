--TEST--
rattle fuzz with the options that change how an input is executed, for one target and for several targets that are fuzzed together
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-execution-options';
$fixtures  = 'tests/fixtures/targets';

foreach ([[$fixtures . '/magic/target.php'], [$fixtures . '/magic/target.php', $fixtures . '/exit/target.php']] as $targets) {
    exec('rm -rf ' . escapeshellarg($directory));

    var_dump(
        (new SebastianBergmann\Rattle\Application)->run(
            [
                'rattle',
                'fuzz',
                ...$targets,
                '--bootstrap', $fixtures . '/bootstrap.php',
                '--source', $fixtures . '/magic/src',
                '--dictionary', $fixtures . '/magic/dictionary.txt',
                '--timeout', '5',
                '--memory-limit', '256M',
                '--max-choices', '10',
                '--ignore-deprecations',
                '--opcache',
                '--seed', '1',
                '--jobs', '1',
                '--max-executions', '3000',
                '--stop-on-finding',
                '--cache-directory', $directory . '/cache',
                '--findings-directory', $directory . '/findings',
            ],
        ),
    );

    // a finding is reproduced with the same options
    foreach ((array) glob($directory . '/findings/magic-header/*/README.txt') as $readme) {
        print (string) file_get_contents((string) $readme);
    }
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   magic-header (bytes, at most 64)
%A
The maximum number of choices was raised from 10 to 129, which the target needs.
%A
int(1)
DivisionByZeroError at tests/fixtures/targets/magic/src/ImageDecoder.php:37

Reproduce it with:

    rattle run --bootstrap 'tests/fixtures/targets/bootstrap.php' --timeout 5 --memory-limit '256M' --max-choices 10 --ignore-deprecations --opcache tests/fixtures/targets/magic/target.php %s/rattle-phpt-fuzz-execution-options/findings/magic-header/%x/input
rattle %s by Sebastian Bergmann.

Target:   2 fuzz targets (magic-header, exit)
%A
The maximum number of choices was raised from 10 to 129 for magic-header, which it needs.
The maximum number of choices was raised from 10 to 129 for exit, which it needs.
%A
int(1)
DivisionByZeroError at tests/fixtures/targets/magic/src/ImageDecoder.php:37

Reproduce it with:

    rattle run --bootstrap 'tests/fixtures/targets/bootstrap.php' --timeout 5 --memory-limit '256M' --max-choices 10 --ignore-deprecations --opcache tests/fixtures/targets/magic/target.php %s/rattle-phpt-fuzz-execution-options/findings/magic-header/%x/input
