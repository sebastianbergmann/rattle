--TEST--
rattle fuzz --phpunit --configuration <phpunit.xml>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-phpunit';

exec('rm -rf ' . escapeshellarg($directory));

var_dump(
    (new SebastianBergmann\Rattle\Application)->run(
        [
            'rattle',
            'fuzz',
            '--phpunit',
            '--configuration', 'tests/fixtures/phpunit/phpunit.xml',
            '--seed', '20',
            '--jobs', '1',
            '--max-executions', '1000',
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

Target:   PHPUnit test suite (2 properties)
Seed:     20
Jobs:     1
Sources:  %s/tests/fixtures/phpunit/src
Corpus:   %s/rattle-phpt-fuzz-phpunit/cache (0 stored inputs)
Findings: %s/rattle-phpt-fuzz-phpunit/findings (0 known)
%A
Finding: LogicException at src/Classifier.php:38
         SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList
         %s/rattle-phpt-fuzz-phpunit/findings/classifiertest-testclassifieseverylist-%x/%x
%A
Property %s Executions  Features  Corpus  Findings    All runs  Last new feature
SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList %s
SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testCountsAreNotNegative %s

1 new finding, 0 known findings were found again.
%A
1 finding was written to %s/rattle-phpt-fuzz-phpunit/findings.sarif in SARIF.
int(1)
