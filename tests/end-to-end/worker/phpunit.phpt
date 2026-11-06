--TEST--
A worker without a target lists the properties of a PHPUnit test suite and runs the examples of all of them
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\PhpunitTestSuite;
use SebastianBergmann\Rattle\WorkerConfiguration;

$classifies = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList';
$counts     = 'SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testCountsAreNotNegative';

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('', null, [realpath('tests/fixtures/phpunit/src')], 5, 8192, null, false, 100, new PhpunitTestSuite('tests/fixtures/phpunit/phpunit.xml', null)),
    [
        [
            'type'   => 'run',
            'inputs' => [
                // the list 7, 3, 9 cannot be classified
                Job::replay(1, [1, 7, 1, 3, 1, 9, 0])->withTarget($classifies)->asArray(),
                Job::replay(2, [1, 7, 0], true)->withTarget($classifies)->asArray(),
                Job::generate(3, 1)->withTarget($counts)->asArray(),
            ],
        ],
        [
            'type'   => 'run',
            'inputs' => [
                Job::replay(4, [0])->withTarget('OtherTest::testOther')->asArray(),
            ],
        ],
    ],
);
--EXPECTF--
Exit: 2
properties, maximumNumberOfChoices: 8192, properties: SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList, SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testCountsAreNotNegative
result 1: failed (LogicException at tests/fixtures/phpunit/src/Classifier.php:38)%A
result 2: passed%A
result 3: passed%A
error: An input does not name a property of the test suite
