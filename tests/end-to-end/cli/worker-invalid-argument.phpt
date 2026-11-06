--TEST--
rattle worker --timeout
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

var_dump((new SebastianBergmann\Rattle\Application)->run(['rattle', 'worker', '--timeout']));
--EXPECTF--
%a{"type":"error","message":"Invalid worker argument \"--timeout\""}int(2)
