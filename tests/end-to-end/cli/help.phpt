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
  rattle fuzz     [options] --phpunit
  rattle run      [options] <target.php> <input>
  rattle run      [options] --phpunit <test-id> <input>
  rattle minimize [options] <target.php> <input>
  rattle minimize [options] --phpunit <test-id> <input>
  rattle regress  [options] <target.php>
  rattle regress  [options] --phpunit
  rattle status   [options] --phpunit

  rattle --help
  rattle --version

Commands:

  fuzz                        Fuzz the target until a limit is reached or Ctrl+C is pressed
  run                         Run one input once and show what happened
  minimize                    Minimize one input and write it to <input>.minimized
  regress                     Replay every input in the corpus and findings directories once
  status                      Show what the runs found out about the properties of a test suite

Options for "rattle fuzz":

  --jobs <n>                  Number of worker processes (default: number of CPU cores)
  --seed <n>                  Seed of the run (default: random)
  --max-time <duration>       Stop after this duration, for instance 90s, 30m, 12h, or 2d
  --max-executions <n>        Stop after this number of executions
  --max-shrink-attempts <n>   Maximum number of attempts to minimize a finding (default: 500)
  --worker-executions <n>     Replace a worker after this number of executions (default: 10000)
  --corpus <dir>              Import the choice sequences in this directory
  --seeds <dir>               Import the raw files in this directory (bytes targets only)
  --dictionary <file>         Insert the tokens of this dictionary file
  --cache-directory <dir>     Directory for the corpus (default: .rattle.cache)
  --findings-directory <dir>  Directory for the findings (default: fuzz/findings)
  --stop-on-finding           Stop after the first new finding
  --sarif <file>              Write the findings of the run to this file in SARIF
  --phpunit                   Fuzz the properties of the PHPUnit test suite together instead
                              of a target: they share the time of --max-time, and
                              --max-executions applies to every property
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)
  --filter <pattern>          Only fuzz the properties that match the pattern of PHPUnit's --filter

Options for "rattle run":

  --raw                       The input is a raw file instead of a choice sequence (bytes targets only)
  --phpunit                   Run an input of the property with the test ID <test-id>
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)

Options for "rattle minimize":

  --max-shrink-attempts <n>   Maximum number of attempts to minimize the input (default: 500)
  --phpunit                   Minimize an input of the property with the test ID <test-id>
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)

Options for "rattle regress":

  --jobs <n>                  Number of worker processes (default: 1)
  --cache-directory <dir>     Directory for the corpus (default: .rattle.cache)
  --findings-directory <dir>  Directory for the findings (default: fuzz/findings)
  --sarif <file>              Write the inputs that still fail to this file in SARIF
  --phpunit                   Replay the inputs of every property of the PHPUnit test suite
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)
  --filter <pattern>          Only replay the inputs of the properties that match the pattern

Options for "rattle status":

  --cache-directory <dir>     Directory for the corpus (default: .rattle.cache)
  --json                      Show the status in JSON
  --html <file>               Write the status to this file as a dashboard in HTML

Options for all commands:

  --timeout <seconds>         Time limit for one execution (default: 1)
  --memory-limit <value>      Memory limit of the worker processes (default: from php.ini)
  --max-choices <n>           Maximum number of choices of an input (default: 8192)
  --bootstrap <file>          Bootstrap file (default: vendor/autoload.php)
  --source <dir>              Instrument the code in this directory, can be repeated
                              (default: <source> of the PHPUnit configuration, otherwise src/)
  --ignore-deprecations       Deprecations are not findings
  --verbose                   Show what the worker processes write to standard error

Exit codes: 0 when there is no new finding, 1 when there is a new finding
(regress: when an input fails), and 2 when the usage or configuration is invalid.
int(0)
