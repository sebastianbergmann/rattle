--TEST--
A worker for a property that a PHPUnit test suite does not have does not start
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\PhpunitTestSuite;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('OtherTest::testOther', null, [], 5, 8192, null, false, 100, new PhpunitTestSuite('tests/fixtures/phpunit/phpunit.xml', null)),
    [],
);
--EXPECT--
Exit: 2
error: The PHPUnit test suite has no property "OtherTest::testOther"
