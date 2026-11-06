<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceResolver::class)]
#[Small]
final class SourceResolverTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/source-resolver';

    public function testPrefersSourcesThatAreGivenExplicitly(): void
    {
        $this->assertSame(['lib', 'other'], (new SourceResolver)->resolve(['lib', 'other'], self::FIXTURES . '/with-phpunit-xml'));
    }

    public function testUsesTheIncludedDirectoriesAndFilesOfThePhpUnitConfiguration(): void
    {
        $directory = self::FIXTURES . '/with-phpunit-xml';

        $this->assertSame(
            [$directory . '/lib', $directory . '/bootstrap.php', '/absolute/path'],
            (new SourceResolver)->resolve([], $directory),
        );
    }

    public function testUsesTheDistributedPhpUnitConfiguration(): void
    {
        $directory = self::FIXTURES . '/with-phpunit-xml-dist';

        $this->assertSame([$directory . '/app'], (new SourceResolver)->resolve([], $directory));
    }

    public function testUsesTheDistributedPhpUnitConfigurationWithItsNewName(): void
    {
        $directory = self::FIXTURES . '/with-phpunit-dist-xml';

        $this->assertSame([$directory . '/lib'], (new SourceResolver)->resolve([], $directory));
    }

    public function testUsesTheConfigurationFileThatIsGivenRelativeToItsDirectory(): void
    {
        $this->assertSame(
            [self::FIXTURES . '/with-phpunit-xml-dist/app'],
            (new SourceResolver)->resolve([], self::FIXTURES . '/without-sources', self::FIXTURES . '/with-phpunit-xml-dist/phpunit.xml.dist'),
        );
    }

    public function testFallsBackToTheSrcDirectory(): void
    {
        $directory = self::FIXTURES . '/with-src';

        $this->assertSame([$directory . '/src'], (new SourceResolver)->resolve([], $directory));
    }

    public function testFallsBackToTheSrcDirectoryWhenThePhpUnitConfigurationIncludesNoSources(): void
    {
        $directory = self::FIXTURES . '/with-phpunit-xml-without-sources';

        $this->assertSame([$directory . '/src'], (new SourceResolver)->resolve([], $directory));
    }

    public function testFindsNoSourcesOtherwise(): void
    {
        $this->assertSame([], (new SourceResolver)->resolve([], self::FIXTURES . '/without-sources'));
    }
}
