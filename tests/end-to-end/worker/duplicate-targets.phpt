--TEST--
A worker does not start when two of its target files return targets with the same name
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('', 'tests/fixtures/worker/bootstrap.php', [], 5, 8192, null, false, 100, null, false, ['tests/fixtures/worker/target.php', 'tests/fixtures/worker/duplicate-target.php']),
    [],
);
--EXPECT--
Exit: 2
error: The target files "tests/fixtures/worker/target.php" and "tests/fixtures/worker/duplicate-target.php" both return a target named "worker"
