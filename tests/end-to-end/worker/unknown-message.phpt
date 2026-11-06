--TEST--
A worker stops when it receives a message that it does not know
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [], 5, 8192, null, false, 100),
    [['type' => 'other']],
);
--EXPECT--
Exit: 2
ready, name: worker, maximumNumberOfChoices: 8192
error: Unknown message
