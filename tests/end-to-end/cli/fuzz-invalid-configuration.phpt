--TEST--
rattle fuzz <target.php>... with configurations that are not valid
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

use SebastianBergmann\Rattle\ArgumentsBuilder;
use SebastianBergmann\Rattle\FuzzCommand;

$directory = sys_get_temp_dir() . '/rattle-phpt-fuzz-invalid-configuration';
$targets   = 'tests/fixtures/targets';
$worker    = 'tests/fixtures/worker';

foreach ([
    [$targets . '/magic/target.php', '--bootstrap', $targets . '/bootstrap.php', '--corpus', '/does/not/exist'],
    [$targets . '/magic/target.php', '--bootstrap', $targets . '/bootstrap.php', '--seeds', '/does/not/exist'],
    [$worker . '/target.php', '--bootstrap', $worker . '/bootstrap.php', '--seeds', 'tests/fixtures/engine/seeds'],
    [$targets . '/magic/target.php', '--bootstrap', $targets . '/bootstrap.php', '--dictionary', 'tests/fixtures/engine/invalid-dictionary.txt'],
    [$worker . '/not-a-target.php', '--bootstrap', $worker . '/bootstrap.php'],
    [$targets . '/magic/target.php', $targets . '/exit/target.php', '--bootstrap', $targets . '/bootstrap.php', '--corpus', 'tests/fixtures/engine/corpus'],
    [$worker . '/target.php', $worker . '/duplicate-target.php', '--bootstrap', $worker . '/bootstrap.php'],
] as $arguments) {
    var_dump(
        (new FuzzCommand)->run(
            (new ArgumentsBuilder)->build(
                ['rattle', 'fuzz', ...$arguments, '--jobs', '1', '--cache-directory', $directory . '/cache', '--findings-directory', $directory . '/findings'],
            ),
        ),
    );
}

exec('rm -rf ' . escapeshellarg($directory));
--EXPECT--
The corpus directory "/does/not/exist" does not exist
int(2)
The seeds directory "/does/not/exist" does not exist
int(2)
Raw seed files from "tests/fixtures/engine/seeds" can only be imported for a bytes target
int(2)
Line 2 of the dictionary is not valid
int(2)
The worker could not be started: The target file "tests/fixtures/worker/not-a-target.php" must return an instance of SebastianBergmann\Rattle\Target, string returned
int(2)
The options --corpus, --seeds, --log-inputs, and --log-coverage can only be used when one target is fuzzed
int(2)
The worker could not be started: The target files "tests/fixtures/worker/target.php" and "tests/fixtures/worker/duplicate-target.php" both return a target named "worker"
int(2)
