--TEST--
A worker reports the input during which the target exited
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, null, false, 100),
    [
        // the target exits
        ['type' => 'run', 'inputs' => [Job::replay(1, [0, 0])->asArray(), Job::replay(2, [4, 0])->asArray()]],
    ],
);
--EXPECTF--
ready, name: worker, maximumNumberOfChoices: 8192
result 1: passed, %d tokens
result 2: failed (exit), last
