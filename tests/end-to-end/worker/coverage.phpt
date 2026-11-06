--TEST--
A worker identifies the paths that the inputs that ask for their coverage took, whatever their outcomes, reports the features of every path once, and reports the blocks of the files that it instrumented with the next result of such an input
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', 'tests/fixtures/worker/bootstrap.php', [realpath('tests/fixtures/worker/src')], 5, 8192, null, false, 100),
    [
        [
            'type'   => 'run',
            'inputs' => [
                // passes without asking for its coverage
                Job::replay(1, [0, 1])->asArray(),
                // passes
                Job::replay(2, [0, 0])->withCoverage()->terse()->asArray(),
                // passes on the same path
                Job::replay(3, [0, 0])->withCoverage()->terse()->asArray(),
                // triggers a warning
                Job::replay(4, [1, 0])->withCoverage()->asArray(),
                // rejects the input
                Job::replay(5, [7, 0])->withCoverage()->asArray(),
            ],
        ],
        ['type' => 'shutdown'],
    ],
);
--EXPECTF--
Exit: 0
ready, name: worker, maximumNumberOfChoices: 8192
result 1: passed, %d tokens
result 2: passed, blocks of tests/fixtures/worker/src/Behaviour.php (%d lines, %d blocks), path %s with %d features
result 3: passed, path %s
result 4: failed (Warning at tests/fixtures/worker/src/Behaviour.php:%d), path %s with %d features
result 5: rejected, path %s with %d features
