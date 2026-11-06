--TEST--
rattle fuzz when what it writes to the cache directory and in SARIF cannot be written, for one target and for several targets that are fuzzed together
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

use SebastianBergmann\Rattle\ArgumentsBuilder;
use SebastianBergmann\Rattle\FuzzCommand;

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-cannot-write';
$fixtures  = 'tests/fixtures/targets';

foreach ([[$fixtures . '/magic/target.php'], [$fixtures . '/magic/target.php', $fixtures . '/exit/target.php']] as $targets) {
    exec('rm -rf ' . escapeshellarg($directory));

    // directories are where the files of the cache directory would be written
    foreach (['magic-header/statistics.json', 'magic-header/dictionary.txt', 'status.json'] as $file) {
        mkdir($directory . '/cache/' . $file, 0o777, true);
    }

    foreach ([$directory . '/cache', '/dev/null/cache'] as $cacheDirectory) {
        ob_start();

        $exit = (new FuzzCommand)->run(
            (new ArgumentsBuilder)->build(
                [
                    'rattle',
                    'fuzz',
                    ...$targets,
                    '--bootstrap', $fixtures . '/bootstrap.php',
                    '--source', $fixtures . '/magic/src',
                    '--seed', '1',
                    '--jobs', '1',
                    '--max-executions', '3000',
                    '--stop-on-finding',
                    '--cache-directory', $cacheDirectory,
                    '--findings-directory', $directory . '/findings',
                    '--sarif', '/dev/null/findings.sarif',
                ],
            ),
        );

        // of the output that is buffered, only the lines that report what
        // cannot be written are printed; the status line and the findings
        // are written to the standard output stream, past the buffer
        $output = (string) ob_get_clean();

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'Warning') || str_starts_with($line, 'Cannot')) {
                print $line . "\n";
            }
        }

        var_dump($exit);
    }
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
%AWarning: Cannot write "%s/rattle-phpt-fuzz-cannot-write/cache/magic-header/statistics.json"
Warning: Cannot write "%s/rattle-phpt-fuzz-cannot-write/cache/status.json"
Warning: Cannot write the tokens that were found in comparisons to "%s/rattle-phpt-fuzz-cannot-write/cache/magic-header/dictionary.txt"
Cannot write the SARIF report to "/dev/null/findings.sarif"
int(2)
%ACannot create the corpus directory "/dev/null/cache/magic-header/corpus"
int(2)
%AWarning: Cannot write "%s/rattle-phpt-fuzz-cannot-write/cache/magic-header/statistics.json"
Warning: Cannot write "%s/rattle-phpt-fuzz-cannot-write/cache/status.json"
Warning: Cannot write the tokens that were found in comparisons to "%s/rattle-phpt-fuzz-cannot-write/cache/magic-header/dictionary.txt"
Cannot write the SARIF report to "/dev/null/findings.sarif"
int(2)
%ACannot create the corpus directory "/dev/null/cache/magic-header/corpus"
int(2)
