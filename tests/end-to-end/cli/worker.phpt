--TEST--
rattle worker --target <target.php>, whose supervisor ends the standard input stream
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

var_dump((new SebastianBergmann\Rattle\Application)->run(['rattle', 'worker', '--target', 'tests/fixtures/worker/target.php', '--bootstrap', 'tests/fixtures/worker/bootstrap.php']));
--EXPECTF--
%a{"type":"ready","protocol":1,"pid":%d,"name":"worker","maximumLength":null,"maximumNumberOfChoices":8192}int(0)
