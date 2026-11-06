--TEST--
rattle status --json --cache-directory <directory in which nothing was fuzzed>
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

var_dump((new SebastianBergmann\Rattle\Application)->run(['rattle', 'status', '--json', '--cache-directory', __DIR__ . '/does-not-exist']));
--EXPECTF--
Nothing was fuzzed with the cache directory %sdoes-not-exist
int(2)
