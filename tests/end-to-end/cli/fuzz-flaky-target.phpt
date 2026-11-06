--TEST--
rattle fuzz <target.php> for a target whose minimal input does not fail in the same way again when it is replayed
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-flaky-target';

exec('rm -rf ' . escapeshellarg($directory));

var_dump(
    (new SebastianBergmann\Rattle\Application)->run(
        [
            'rattle',
            'fuzz',
            'tests/fixtures/targets/flaky/target.php',
            '--seed', '1',
            '--jobs', '1',
            '--max-executions', '2000',
            '--stop-on-finding',
            '--cache-directory', $directory . '/cache',
            '--findings-directory', $directory . '/findings',
        ],
    ),
);

foreach ((array) glob($directory . '/findings/flaky/*/README.txt') as $readme) {
    print (string) file_get_contents((string) $readme);
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   flaky (bytes, at most 16)
%A
Finding: LogicException at tests/fixtures/targets/flaky/target.php:22 (the minimal input did not fail again, kept the input as found)
         %s/rattle-phpt-fuzz-flaky-target/findings/flaky/%x
%A
1 new finding, %d known findings were found again.
int(1)
LogicException at tests/fixtures/targets/flaky/target.php:22

The minimal input did not fail in the same way again when it was replayed, so the input as it was found is kept.

Reproduce it with:

    rattle run tests/fixtures/targets/flaky/target.php %s/rattle-phpt-fuzz-flaky-target/findings/flaky/%x/input
