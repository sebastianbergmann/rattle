<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle;

use function defined;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class HelpCommand implements Command
{
    public function run(Arguments $arguments): int
    {
        print <<<'EOT'
Usage:
  rattle fuzz     [options] <target.php>
  rattle fuzz     [options] --phpunit
  rattle run      [options] <target.php> <input>
  rattle run      [options] --phpunit <test-id> <input>
  rattle minimize [options] <target.php> <input>
  rattle regress  [options] <target.php>

  rattle --help
  rattle --version

Commands:

  fuzz                        Fuzz the target until a limit is reached or Ctrl+C is pressed
  run                         Run one input once and show what happened
  minimize                    Minimize one input and write it to <input>.minimized
  regress                     Replay every input in the corpus and findings directories once

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
  --phpunit                   Fuzz the properties of the PHPUnit test suite one after another
                              instead of a target: they share the time of --max-time, and
                              --max-executions applies to every property
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)
  --filter <pattern>          Only fuzz the properties that match the pattern of PHPUnit's --filter

Options for "rattle run":

  --raw                       The input is a raw file instead of a choice sequence (bytes targets only)
  --phpunit                   Run an input of the property with the test ID <test-id>
  --configuration <file>      PHPUnit configuration file (default: as PHPUnit looks for one)

Options for "rattle minimize":

  --max-shrink-attempts <n>   Maximum number of attempts to minimize the input (default: 500)

Options for "rattle regress":

  --jobs <n>                  Number of worker processes (default: 1)
  --cache-directory <dir>     Directory for the corpus (default: .rattle.cache)
  --findings-directory <dir>  Directory for the findings (default: fuzz/findings)

Options for all commands:

  --timeout <seconds>         Time limit for one execution (default: 1)
  --memory-limit <value>      Memory limit of the worker processes (default: from php.ini)
  --max-choices <n>           Maximum number of choices of an input (default: 8192)
  --bootstrap <file>          Bootstrap file (default: vendor/autoload.php)
  --source <dir>              Instrument the code in this directory, can be repeated
                              (default: <source> of the PHPUnit configuration, otherwise src/)
  --ignore-deprecations       Deprecations are not findings
  --verbose                   Show what the worker processes write to standard error


EOT;

        if (defined('__RATTLE_PHAR__')) {
            // @codeCoverageIgnoreStart
            print <<<'EOT'
Options of the PHAR:

  --manifest                  Print Software Bill of Materials (SBOM) in plain-text format
  --sbom                      Print Software Bill of Materials (SBOM) in CycloneDX XML format
  --composer-lock             Print the composer.lock file that the PHAR was built with


EOT;
            // @codeCoverageIgnoreEnd
        }

        print <<<'EOT'
Exit codes: 0 when there is no new finding, 1 when there is a new finding
(regress: when an input fails), and 2 when the usage or configuration is invalid.

EOT;

        return 0;
    }
}
