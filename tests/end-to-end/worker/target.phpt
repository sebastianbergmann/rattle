--TEST--
A worker that loads one target file executes the inputs of its target
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

// the first choice of an input is the behaviour of the target (see
// SebastianBergmann\Rattle\Fixture\Worker\Behaviour, which is loaded and
// instrumented by the worker)
SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, null, false, 100),
    [
        [
            'type'   => 'run',
            'inputs' => [
                // passes
                Job::replay(1, [0, 0])->asArray(),
                // triggers a warning
                Job::replay(2, [1, 0])->asArray(),
                // throws an exception
                Job::replay(3, [6, 0], true)->asArray(),
            ],
        ],
        [
            'type'   => 'run',
            'inputs' => [
                // rejects the input
                Job::replay(4, [7, 0])->asArray(),
                // throws the exception that the target expects
                Job::replay(5, [9, 0])->asArray(),
            ],
        ],
        ['type' => 'shutdown'],
    ],
);
--EXPECTF--
Exit: 0
ready, name: worker, maximumNumberOfChoices: 8192
result 1: passed, %d tokens
result 2: failed (Warning at tests/fixtures/worker/src/Behaviour.php:%d)
result 3: failed (LogicException at tests/fixtures/worker/src/Behaviour.php:%d)
result 4: rejected
result 5: expected
