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

use function dirname;
use function escapeshellarg;
use function exec;
use function getenv;
use function is_executable;
use function proc_close;
use function proc_open;
use function putenv;
use function shell_exec;
use function strrpos;
use function substr;
use function substr_count;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

#[CoversClass(SentinelCommand::class)]
#[Medium]
final class SentinelCommandTest extends TestCase
{
    private int $runs = 0;

    protected function setUp(): void
    {
        SentinelCommand::install(function (): void
        {
            $this->runs++;
        });
    }

    protected function tearDown(): void
    {
        SentinelCommand::uninstall();
    }

    public function testCallsTheHandlerWhenTheSentinelCommandRunsAsACommand(): void
    {
        exec(Sentinels::NAME);

        $this->assertSame(1, $this->runs);

        $process = proc_open([Sentinels::NAME], [], $pipes);

        $this->assertNotFalse($process);

        proc_close($process);

        $this->assertSame(2, $this->runs);
    }

    public function testCallsTheHandlerWhenTheSentinelCommandRunsInACommandLineThatAShellInterprets(): void
    {
        shell_exec('echo $(' . Sentinels::NAME . ')');
        shell_exec('echo x; ' . Sentinels::NAME);

        $this->assertSame(2, $this->runs);
    }

    public function testDoesNotCallTheHandlerWhenTheSentinelCommandIsAnArgumentThatIsEscaped(): void
    {
        shell_exec('echo ' . escapeshellarg('$(' . Sentinels::NAME . ')'));

        $this->assertSame(0, $this->runs);
    }

    public function testAppendsTheDirectoryOfTheSentinelCommandToThePathAndRestoresThePathWhenItIsUninstalled(): void
    {
        $path      = (string) getenv('PATH');
        $separator = (int) strrpos($path, ':');
        $directory = substr($path, $separator + 1);

        $this->assertTrue(is_executable($directory . '/' . Sentinels::NAME));

        SentinelCommand::uninstall();

        $this->assertSame(substr($path, 0, $separator), getenv('PATH'));
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testCreatesItsDirectoryInTheDirectoryOfTheWorkersOfTheSupervisorWhichRemovesWhatIsLeftInIt(): void
    {
        SentinelCommand::uninstall();

        $environment = SentinelCommand::environmentOfWorkers();

        $this->assertSame($environment, SentinelCommand::environmentOfWorkers());

        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);
        }

        SentinelCommand::install(static function (): void
        {
        });

        foreach ($environment as $name => $value) {
            putenv($name);
        }

        $path      = (string) getenv('PATH');
        $directory = substr($path, (int) strrpos($path, ':') + 1);
        $workers   = $environment['RATTLE_SENTINEL_DIRECTORY'] ?? '';

        $this->assertSame($workers, dirname($directory));

        // the worker is killed and does not remove its directory
        SentinelCommand::removeDirectoryOfWorkers();

        $this->assertDirectoryDoesNotExist($workers);
    }

    public function testIsInstalledOnlyOnce(): void
    {
        $runs = 0;

        SentinelCommand::install(static function () use (&$runs): void
        {
            $runs++;
        });

        exec(Sentinels::NAME);

        $this->assertSame(1, $this->runs);
        $this->assertSame(0, $runs);
        $this->assertSame(1, substr_count((string) getenv('PATH'), '/rattle-sentinel-'));
    }

    public function testUnsetsThePathWhenItIsUninstalledWhenThePathWasNotSetWhenItWasInstalled(): void
    {
        SentinelCommand::uninstall();

        $path = (string) getenv('PATH');

        putenv('PATH');

        try {
            SentinelCommand::install(static function (): void
            {
            });

            $directory = (string) getenv('PATH');

            $this->assertTrue(is_executable($directory . '/' . Sentinels::NAME));

            SentinelCommand::uninstall();

            $this->assertFalse(getenv('PATH'));
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testSetsThePathInTheEnvironmentVariablesOfPhp(): void
    {
        SentinelCommand::uninstall();

        $path         = (string) getenv('PATH');
        $hadPath      = isset($_ENV['PATH']);
        $_ENV['PATH'] = $path;

        try {
            SentinelCommand::install(static function (): void
            {
            });

            $installed = $_ENV['PATH'] ?? null;

            $this->assertSame(getenv('PATH'), $installed);
            $this->assertNotSame($path, $installed);

            SentinelCommand::uninstall();

            $this->assertSame($path, $_ENV['PATH'] ?? null);
        } finally {
            if (!$hadPath) {
                unset($_ENV['PATH']);
            }
        }
    }

    public function testRemovingTheDirectoryOfTheWorkersAgainDoesNothing(): void
    {
        $workers = SentinelCommand::environmentOfWorkers()['RATTLE_SENTINEL_DIRECTORY'] ?? '';

        SentinelCommand::removeDirectoryOfWorkers();
        SentinelCommand::removeDirectoryOfWorkers();

        $this->assertDirectoryDoesNotExist($workers);
    }
}
