--TEST--
With --verbose, what the workers write to their standard error streams is shown, prefixed with the number of the worker
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

use SebastianBergmann\Rattle\ArgumentsBuilder;
use SebastianBergmann\Rattle\Setup;

$stderr = (new Setup)->stderr((new ArgumentsBuilder)->build(['rattle', 'fuzz', 'target.php', '--verbose']));

assert($stderr !== null);

$stderr(2, "first line\nsecond line\n");
--EXPECT--
[worker 2] first line
[worker 2] second line
