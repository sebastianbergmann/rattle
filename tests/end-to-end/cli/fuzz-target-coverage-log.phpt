--TEST--
rattle fuzz <target.php> --log-coverage <file>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-target-coverage-log';

exec('rm -rf ' . escapeshellarg($directory));
mkdir($directory);

$fixtures = 'tests/fixtures/targets';

foreach ([$directory . '/coverage.jsonl', '/dev/null/coverage.jsonl'] as $file) {
    var_dump(
        (new SebastianBergmann\Rattle\Application)->run(
            [
                'rattle',
                'fuzz',
                $fixtures . '/magic/target.php',
                '--bootstrap', $fixtures . '/bootstrap.php',
                '--source', $fixtures . '/magic/src',
                '--log-coverage', $file,
                '--seed', '1',
                '--jobs', '1',
                '--max-executions', '2000',
                '--stop-on-finding',
                '--cache-directory', $directory . '/cache',
                '--findings-directory', $directory . '/findings',
            ],
        ),
    );
}

$blocks     = [];
$paths      = [];
$executions = 0;

foreach ((array) file($directory . '/coverage.jsonl') as $line) {
    $event = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);

    switch ($event['event']) {
        case 'start':
            printf("start: version %d, target %s, seed %d, jobs %d\n", $event['version'], $event['target'], $event['seed'], $event['jobs']);

            break;

        case 'file':
            printf("file: %s, %d lines, %s\n", $event['file'], $event['lines'], $event['blocks'] === [] ? 'no blocks' : 'blocks on lines ' . implode(', ', array_column($event['blocks'], 1)));

            foreach ($event['blocks'] as [$block]) {
                $blocks[$block] = true;
            }

            break;

        case 'path':
            foreach ($event['edges'] as [$from, $to]) {
                if (($from !== 0 && !isset($blocks[$from])) || !isset($blocks[$to])) {
                    print "path with an edge between blocks that are not known\n";
                }
            }

            $paths[$event['path']] = true;

            break;

        case 'execution':
            $executions++;

            if (!isset($event['path'], $paths[$event['path']])) {
                print "execution without a path that is known\n";
            }

            if (isset($event['finding'])) {
                printf("finding: %s, %s, %s, values %s\n", $event['finding']['new'] ? 'new' : 'known', $event['origin'], $event['finding']['failure'], implode(', ', $event['values']));
            }

            break;

        case 'end':
            printf("end: %s executions, %d features, %d corpus entries, %d findings\n", $event['executions'] === $executions ? 'all' : 'not all', $event['features'], $event['corpusEntries'], $event['findings']);
    }
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   magic-header (bytes, at most 64)
%A
1 new finding, 0 known findings were found again.
%A
int(1)
rattle %s by Sebastian Bergmann.

Cannot write the coverage log "/dev/null/coverage.jsonl"
int(2)
start: version 1, target magic-header, seed 1, jobs 1
file: tests/fixtures/targets/magic/src/ParseException.php, 16 lines, no blocks
file: tests/fixtures/targets/magic/src/ImageDecoder.php, 41 lines, blocks on lines 28, 29, 32, 33, 36
finding: new, mutated, DivisionByZeroError at tests/fixtures/targets/magic/src/ImageDecoder.php:37, values "RATL\x00%S"
end: all executions, %d features, %d corpus entries, 1 findings
