--TEST--
rattle --help
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

$_SERVER['argv'][1] = '--help';

var_dump((new SebastianBergmann\Rattle\Application)->run($_SERVER['argv']));
--EXPECTF--
rattle %s by Sebastian Bergmann.

Usage:
  rattle fuzz     [options] <target.php>
  rattle run      [options] <target.php> <input>
  rattle minimize [options] <target.php> <input>
  rattle regress  [options] <target.php>

  rattle --help
  rattle --version
int(0)
