--TEST--
rattle fuzz --phpunit --filter <property> --log-coverage <file>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-phpunit-property-coverage-log';

exec('rm -rf ' . escapeshellarg($directory));
mkdir($directory);

foreach ([$directory . '/coverage.jsonl', '/dev/null/coverage.jsonl'] as $file) {
    var_dump(
        (new SebastianBergmann\Rattle\Application)->run(
            [
                'rattle',
                'fuzz',
                '--phpunit',
                '--configuration', 'tests/fixtures/phpunit/characters.xml',
                '--filter', 'testParsesEveryCommand',
                '--log-coverage', $file,
                '--seed', '1',
                '--jobs', '1',
                '--max-executions', '300',
                '--cache-directory', $directory . '/cache',
                '--findings-directory', $directory . '/findings',
            ],
        ),
    );
}

$events = [];
$files  = [];

foreach ((array) file($directory . '/coverage.jsonl') as $line) {
    $event = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);

    $events[$event['event']] = ($events[$event['event']] ?? 0) + 1;

    if ($event['event'] === 'start') {
        printf("start: target %s\n", $event['target']);
    }

    if ($event['event'] === 'file') {
        $files[] = $event['file'];
    }

    if ($event['event'] === 'end') {
        printf("end: %s executions\n", $event['executions'] === ($events['execution'] ?? 0) ? 'all' : 'not all');
    }
}

printf("files: %s\n", implode(', ', $files));
printf("paths: %s\n", ($events['path'] ?? 0) > 1 ? 'several' : 'one or none');

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   PHPUnit test suite (1 property)
%A
int(1)
rattle %s by Sebastian Bergmann.

%ACannot write the coverage log "/dev/null/coverage.jsonl"
int(2)
start: target SebastianBergmann\Rattle\Fixture\Phpunit\CommandParserTest::testParsesEveryCommand
end: all executions
files: tests/fixtures/phpunit/src/CommandParser.php
paths: several
