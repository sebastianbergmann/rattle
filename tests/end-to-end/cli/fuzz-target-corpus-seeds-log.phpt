--TEST--
rattle fuzz <target.php> --corpus <directory> --seeds <directory> --log-inputs <file>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-target-corpus-seeds-log';

exec('rm -rf ' . escapeshellarg($directory));
mkdir($directory);

$fixtures = 'tests/fixtures/targets';

var_dump(
    (new SebastianBergmann\Rattle\Application)->run(
        [
            'rattle',
            'fuzz',
            $fixtures . '/magic/target.php',
            '--bootstrap', $fixtures . '/bootstrap.php',
            '--source', $fixtures . '/magic/src',
            '--corpus', 'tests/fixtures/engine/corpus',
            '--seeds', 'tests/fixtures/engine/seeds',
            '--log-inputs', $directory . '/inputs.log',
            '--seed', '1',
            '--jobs', '1',
            '--max-executions', '100',
            '--cache-directory', $directory . '/cache',
            '--findings-directory', $directory . '/findings',
        ],
    ),
);

// the inputs of the corpus directory and the raw seed files are replayed first
print implode('', array_slice((array) file($directory . '/inputs.log'), 0, 6));

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   magic-header (bytes, at most 64)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/magic/src
Corpus:   %s/rattle-phpt-fuzz-target-corpus-seeds-log/cache/magic-header/corpus (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-target-corpus-seeds-log/findings/magic-header (0 known)

Warning: tests/fixtures/engine/corpus/invalid is not a valid choice sequence: %s
%A
0 new findings, 0 known findings were found again.
%A
int(0)
replay 0
replay 0
replay 1,82,0
replay 1,97,1,98,0
replay 1,71,1,73,1,70,1,56,1,57,1,97,0
generate %i
