--TEST--
A worker that loads several target files executes the inputs of all their targets
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, null, false, 100, null, false, ['tests/fixtures/worker/target.php', 'tests/fixtures/worker/bytes-target.php']),
    [
        [
            'type'   => 'run',
            'inputs' => [
                // the constant operands of the comparisons that were found
                // while the worker started are reported with the first
                // result of every target
                Job::replay(1, [0, 0])->withTarget('worker')->asArray(),
                Job::replay(2, [0])->withTarget('bytes')->asArray(),
                Job::replay(3, [0, 0])->withTarget('worker')->asArray(),
            ],
        ],
        [
            'type'   => 'run',
            'inputs' => [
                Job::replay(4, [0])->withTarget('other')->asArray(),
            ],
        ],
    ],
);
--EXPECTF--
Exit: 2
targets, targets: worker, bytes
result 1: passed, %d tokens
result 2: passed, %d tokens
result 3: passed
error: An input does not name one of the targets of the worker
