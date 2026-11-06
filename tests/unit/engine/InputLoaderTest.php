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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputLoader::class)]
#[UsesClass(BytesEncoding::class)]
#[Small]
final class InputLoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/engine';

    public function testLoadsChoiceSequencesInTheOrderOfTheirFileNames(): void
    {
        $loader = new InputLoader;

        $this->assertSame([[1, 82, 0], [0]], $loader->choiceSequencesIn(self::FIXTURES . '/corpus'));
        $this->assertCount(1, $loader->warnings());
        $this->assertStringContainsString('invalid is not a valid choice sequence', $loader->warnings()[0]);
    }

    public function testLoadsRawFilesAsTheChoicesOfTheirBytes(): void
    {
        $this->assertSame(
            [
                BytesEncoding::choicesFor('GIF89a', 4),
                [0],
                BytesEncoding::choicesFor('ab', 4),
            ],
            (new InputLoader)->rawFilesIn(self::FIXTURES . '/seeds', 4),
        );
    }

    public function testLoadsNothingFromADirectoryThatDoesNotExist(): void
    {
        $this->assertSame([], (new InputLoader)->choiceSequencesIn('/does/not/exist'));
    }
}
