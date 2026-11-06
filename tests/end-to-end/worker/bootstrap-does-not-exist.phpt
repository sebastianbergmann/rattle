--TEST--
A worker does not start when its bootstrap file does not exist
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/serve.php';

use SebastianBergmann\Rattle\Job;
use SebastianBergmann\Rattle\WorkerConfiguration;

SebastianBergmann\Rattle\EndToEnd\Worker\serve(
    new WorkerConfiguration('tests/fixtures/worker/target.php', '/does/not/exist.php', [], 5, 8192, null, false, 100),
    [],
);
--EXPECT--
Exit: 2
error: The bootstrap file "/does/not/exist.php" does not exist
