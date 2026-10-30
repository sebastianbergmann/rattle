--TEST--
rattle --unknown-option
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$_SERVER['argv'][1] = '--unknown-option';

var_dump((new SebastianBergmann\Rattle\Application)->run($_SERVER['argv']));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Unknown option "--unknown-option"%A
int(2)
