--TEST--
rattle fuzz --php <binary>, for one target and for several targets that are fuzzed together
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-php';
$fixtures  = 'tests/fixtures/targets';

foreach ([[$fixtures . '/versions/target.php'], [$fixtures . '/versions/target.php', $fixtures . '/magic/target.php']] as $targets) {
    exec('rm -rf ' . escapeshellarg($directory));

    var_dump(
        (new SebastianBergmann\Rattle\Application)->run(
            [
                'rattle',
                'fuzz',
                ...$targets,
                '--bootstrap', $fixtures . '/bootstrap.php',
                '--source', $fixtures . '/versions/src',
                '--php', 'tests/fixtures/php/php-precision-5',
                '--seed', '1',
                '--jobs', '1',
                '--max-executions', '500',
                '--stop-on-finding',
                '--cache-directory', $directory . '/cache',
                '--findings-directory', $directory . '/findings',
                '--sarif', $directory . '/findings.sarif',
            ],
        ),
    );
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   ratio (bytes, at most 16)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/versions/src
Corpus:   %s/rattle-phpt-fuzz-php/cache/ratio/corpus (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-php/findings/ratio (0 known)
Versions: %s, %s (php-precision-5), which the corpus and the findings are compared on

Finding: Difference between PHP versions: %s: passes; %s (php-precision-5): LengthException at tests/fixtures/targets/versions/src/Ratio.php:32
         %s/rattle-phpt-fuzz-php/findings/ratio/%x
%A
1 new finding, 0 known findings were found again.
1 finding was written to %s/rattle-phpt-fuzz-php/findings.sarif in SARIF.
int(1)
rattle %s by Sebastian Bergmann.

Target:   2 fuzz targets (ratio, magic-header)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/targets/versions/src
Corpus:   %s/rattle-phpt-fuzz-php/cache (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-php/findings (0 known)
Versions: %s, %s (php-precision-5), which the corpus and the findings are compared on
%A
Finding: Difference between PHP versions: %s: passes; %s (php-precision-5): LengthException at tests/fixtures/targets/versions/src/Ratio.php:32
         ratio
         %s/rattle-phpt-fuzz-php/findings/ratio/%x
%A
1 new finding, 0 known findings were found again.
1 finding was written to %s/rattle-phpt-fuzz-php/findings.sarif in SARIF.
int(1)
