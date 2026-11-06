--TEST--
rattle fuzz <target.php> <target.php>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-targets';

exec('rm -rf ' . escapeshellarg($directory));

$fixtures = 'tests/fixtures/targets';

var_dump(
    (new SebastianBergmann\Rattle\Application)->run(
        [
            'rattle',
            'fuzz',
            $fixtures . '/magic/target.php',
            $fixtures . '/exit/target.php',
            '--bootstrap', $fixtures . '/bootstrap.php',
            '--source', $fixtures . '/magic/src',
            '--source', $fixtures . '/exit/src',
            '--seed', '1',
            '--jobs', '1',
            '--max-executions', '3000',
            '--stop-on-finding',
            '--cache-directory', $directory . '/cache',
            '--findings-directory', $directory . '/findings',
            '--sarif', $directory . '/findings.sarif',
        ],
    ),
);

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   2 fuzz targets (magic-header, exit)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/magic/src, %s/tests/fixtures/targets/exit/src
Corpus:   %s/rattle-phpt-fuzz-targets/cache (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-targets/findings (0 known)
%A
Finding: DivisionByZeroError at tests/fixtures/targets/magic/src/ImageDecoder.php:37
         magic-header
         %s/rattle-phpt-fuzz-targets/findings/magic-header/%x
Finding: Process exited with status 0
         exit
         %s/rattle-phpt-fuzz-targets/findings/exit/%x
%A
Target        Executions  Features  Corpus  Findings    All runs  Last new feature
magic-header %s
exit %s

2 new findings, 0 known findings were found again.
%A
2 findings were written to %s/rattle-phpt-fuzz-targets/findings.sarif in SARIF.
int(1)
