--TEST--
A worker reports what a hook method that is called after the last test of a test class raised on its standard error stream, and runs the example of a property of another test class before which PHPUnit called the hook method
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\PhpunitTestSuite;
use SebastianBergmann\Rattle\WorkerConfiguration;

$failing = 'SebastianBergmann\Rattle\Fixture\Phpunit\FailingTearDownAfterClassTest::testIsChecked';
$passing = 'SebastianBergmann\Rattle\Fixture\Phpunit\PassingTearDownAfterClassTest::testIsChecked';

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('', null, [], 5, 8192, null, false, 100, new PhpunitTestSuite('tests/fixtures/phpunit/hooks.xml', null)),
    [
        [
            'type'   => 'run',
            'inputs' => [
                Job::replay(1, [0, 1])->withTarget($failing)->asArray(),
                Job::replay(2, [0, 2])->withTarget($failing)->asArray(),
                // PHPUnit calls tearDownAfterClass() of the test class of the
                // examples above before it runs this example
                Job::replay(3, [0, 3])->withTarget($passing)->asArray(),
                Job::replay(4, [0, 4])->withTarget($failing)->asArray(),
            ],
        ],
        ['type' => 'shutdown'],
    ],
);
--EXPECTF--
A hook method of SebastianBergmann\Rattle\Fixture\Phpunit\FailingTearDownAfterClassTest that is called after its last test raised RuntimeException: cannot be torn down
A hook method of SebastianBergmann\Rattle\Fixture\Phpunit\FailingTearDownAfterClassTest that is called after its last test raised RuntimeException: cannot be torn down
Exit: 0
properties, maximumNumberOfChoices: 8192, properties: SebastianBergmann\Rattle\Fixture\Phpunit\FailingTearDownAfterClassTest::testIsChecked, SebastianBergmann\Rattle\Fixture\Phpunit\PassingTearDownAfterClassTest::testIsChecked
result 1: passed%A
result 2: passed%A
result 3: passed%A
result 4: passed%A
