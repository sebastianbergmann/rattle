--TEST--
A worker reports the input during which a fatal error occurred
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, '64M', false, 100),
    [
        // the target exhausts the memory
        ['type' => 'run', 'inputs' => [Job::replay(1, [2, 0])->asArray()]],
    ],
);
--EXPECTF--
%A
ready, name: worker, maximumNumberOfChoices: 8192
result 1: failed (Fatal error at tests/fixtures/worker/src/Behaviour.php:%d), %d tokens, last
