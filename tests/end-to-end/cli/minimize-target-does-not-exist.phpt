--TEST--
rattle minimize /does/not/exist.php input
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

var_dump((new SebastianBergmann\Rattle\Application)->run(['rattle', 'minimize', '/does/not/exist.php', 'input']));
--EXPECTF--
rattle %s by Sebastian Bergmann.

The target file "/does/not/exist.php" does not exist
int(2)
