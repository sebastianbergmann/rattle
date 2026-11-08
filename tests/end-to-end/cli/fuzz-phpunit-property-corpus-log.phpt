--TEST--
rattle fuzz --phpunit --filter <property> --corpus <directory> --log-inputs <file>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-phpunit-property-corpus-log';

exec('rm -rf ' . escapeshellarg($directory));
mkdir($directory);

var_dump(
    (new SebastianBergmann\Rattle\Application)->run(
        [
            'rattle',
            'fuzz',
            '--phpunit',
            '--configuration', 'tests/fixtures/phpunit/characters.xml',
            '--filter', 'testParsesEveryCommand',
            '--corpus', 'tests/fixtures/engine/corpus',
            '--log-inputs', $directory . '/inputs.log',
            '--seed', '1',
            '--jobs', '1',
            '--max-executions', '300',
            '--cache-directory', $directory . '/cache',
            '--findings-directory', $directory . '/findings',
        ],
    ),
);

// the inputs of the corpus directory are replayed first
print implode('', array_slice((array) file($directory . '/inputs.log'), 0, 3));

exec('rm -rf ' . escapeshellarg($directory));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Target:   PHPUnit test suite (1 property)
Seed:     1
Jobs:     1
Sources:  %s/tests/fixtures/phpunit/src
Corpus:   %s/rattle-phpt-fuzz-phpunit-property-corpus-log/cache (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-phpunit-property-corpus-log/findings (0 known)

Warning: tests/fixtures/engine/corpus/invalid is not a valid choice sequence: %s
%A
Finding: LogicException at src/CommandParser.php:23
         SebastianBergmann\Rattle\Fixture\Phpunit\CommandParserTest::testParsesEveryCommand
%A
1 token that was found in comparisons was written to the dictionary.txt of every property.
int(1)
replay 0
replay 1,82,0
generate %i
