--TEST--
rattle
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

var_dump((new SebastianBergmann\Rattle\Application)->run(['rattle']));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Usage:
  rattle fuzz     [options] <target.php|directory>...
%A
int(2)
