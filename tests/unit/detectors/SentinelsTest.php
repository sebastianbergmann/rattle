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

use function str_repeat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sentinels::class)]
#[Small]
final class SentinelsTest extends TestCase
{
    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function paths(): array
    {
        return [
            'the sentinel'                                              => [true, '/rattle-sentinel'],
            'the sentinel as a URL'                                     => [true, 'file:///rattle-sentinel'],
            'the sentinel with an extension'                            => [true, '/rattle-sentinel.php'],
            'a file in the sentinel'                                    => [true, '/rattle-sentinel/index.php'],
            'a relative path that climbs to the sentinel'               => [true, '../../../../rattle-sentinel'],
            'a path that climbs to the sentinel from a directory'       => [true, '/project/templates/../../../../../rattle-sentinel'],
            'a path that climbs beyond the root'                        => [true, 'data/' . str_repeat('../', 16) . 'rattle-sentinel'],
            'a path with redundant separators and dots'                 => [true, '/project//./../rattle-sentinel'],
            'a path that climbs into a directory and back'              => [true, '../../../../tmp/../rattle-sentinel'],
            'the sentinel below the working directory'                  => [false, 'rattle-sentinel'],
            'the sentinel below a directory'                            => [false, '/project/data/rattle-sentinel'],
            'a path that does not climb far enough'                     => [false, '../rattle-sentinel'],
            'another file at the root'                                  => [false, '/etc/passwd'],
            'a file whose name contains the sentinel in a subdirectory' => [false, '/tmp/rattle-sentinel'],
            'an empty path'                                             => [false, ''],
        ];
    }

    #[DataProvider('paths')]
    public function testTellsWhetherAPathLeadsToTheSentinelOfTheFileSystem(bool $expected, string $path): void
    {
        $this->assertSame($expected, Sentinels::isPath($path, '/project/working/directory'));
    }

    public function testHasTheTokensThatBringTheSentinelsIntoTheStringsOfInputs(): void
    {
        $this->assertSame(
            [
                str_repeat('../', 16) . 'rattle-sentinel',
                'rattle-sentinel',
                '$(rattle-sentinel)',
                'O:33:"SebastianBergmann\\Rattle\\Sentinel":0:{}',
                'new \\SebastianBergmann\\Rattle\\Sentinel;',
            ],
            Sentinels::tokens(),
        );
    }

    public function testThePathAmongTheTokensLeadsToTheSentinelOfTheFileSystemFromEveryDirectoryThatIsUpTo16LevelsBelowTheRoot(): void
    {
        $path = Sentinels::tokens()[0];

        $this->assertTrue(Sentinels::isPath($path, '/'));
        $this->assertTrue(Sentinels::isPath($path, str_repeat('/directory', 16)));
        $this->assertFalse(Sentinels::isPath($path, str_repeat('/directory', 17)));
    }
}
