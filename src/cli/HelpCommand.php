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
  rattle run      [options] <target.php> <input>
  rattle minimize [options] <target.php> <input>
  rattle regress  [options] <target.php>

  rattle --help
  rattle --version

EOT;

        return 0;
    }
}
