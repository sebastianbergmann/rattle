--TEST--
A worker retires after the batch of inputs with which it reached the number of inputs that it executes
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, '64M', true, 3),
    [
        ['type' => 'run', 'inputs' => [Job::replay(1, [0, 0])->asArray(), Job::replay(2, [0, 1])->asArray()]],
        ['type' => 'run', 'inputs' => [Job::replay(3, [0, 0])->asArray(), Job::replay(4, [0, 1])->asArray()]],
        ['type' => 'run', 'inputs' => [Job::replay(5, [0, 0])->asArray()]],
    ],
);
--EXPECTF--
Exit: 0
ready, name: worker, maximumNumberOfChoices: 8192
result 1: passed, %d tokens
result 2: passed
result 3: passed
result 4: passed, retire
