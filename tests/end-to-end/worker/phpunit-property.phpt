--TEST--
A worker for one property of a PHPUnit test suite runs its examples
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\PhpunitTestSuite;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList', null, [realpath('tests/fixtures/phpunit/src')], 5, 8192, null, false, 100, new PhpunitTestSuite('tests/fixtures/phpunit/phpunit.xml', 'testClassifiesEveryList')),
    [
        ['type' => 'run', 'inputs' => [Job::replay(1, [1, 7, 1, 3, 1, 9, 0])->asArray(), Job::replay(2, [0])->asArray()]],
        ['type' => 'shutdown'],
    ],
);
--EXPECTF--
Exit: 0
ready, name: SebastianBergmann\Rattle\Fixture\Phpunit\ClassifierTest::testClassifiesEveryList, maximumNumberOfChoices: 8192
result 1: failed (LogicException at src/Classifier.php:38)%A
result 2: passed%A
