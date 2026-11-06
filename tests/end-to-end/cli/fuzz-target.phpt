--TEST--
rattle fuzz <target.php>, twice with the same cache and findings directories
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-target';

exec('rm -rf ' . escapeshellarg($directory));

$fixtures  = 'tests/fixtures/targets';
$arguments = [
    'rattle',
    'fuzz',
    $fixtures . '/magic/target.php',
    '--bootstrap', $fixtures . '/bootstrap.php',
    '--source', $fixtures . '/magic/src',
    '--dictionary', $fixtures . '/magic/dictionary.txt',
    '--seed', '1',
    '--jobs', '1',
    '--max-executions', '5000',
    '--stop-on-finding',
    '--cache-directory', $directory . '/cache',
    '--findings-directory', $directory . '/findings',
    '--sarif', $directory . '/findings.sarif',
];

var_dump((new SebastianBergmann\Rattle\Application)->run($arguments));

// the second run starts with the corpus, the finding, and the tokens of the first
var_dump((new SebastianBergmann\Rattle\Application)->run($arguments));

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   magic-header (bytes, at most 64)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/magic/src
Corpus:   %s/rattle-phpt-fuzz-target/cache/magic-header/corpus (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-target/findings/magic-header (0 known)
%A
Finding: DivisionByZeroError at tests/fixtures/targets/magic/src/ImageDecoder.php:37
         %s/rattle-phpt-fuzz-target/findings/magic-header/%x
%A
1 new finding, 0 known findings were found again.
%A
1 finding was written to %s/rattle-phpt-fuzz-target/findings.sarif in SARIF.
int(1)
rattle %s by Sebastian Bergmann.

Target:   magic-header (bytes, at most 64)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/magic/src
Corpus:   %s/rattle-phpt-fuzz-target/cache/magic-header/corpus (%d stored input%s)
Findings: %s/rattle-phpt-fuzz-target/findings/magic-header (1 known)
%A
0 new findings, %d known findings were found again.
%A
int(0)
