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

#[CoversClass(DictionaryWriter::class)]
#[UsesClass(DictionaryParser::class)]
#[Small]
final class DictionaryWriterTest extends TestCase
{
    public function testWritesOneTokenPerLineInDoubleQuotesAfterAComment(): void
    {
        $this->assertSame(
            "# tokens\n\"RATL\"\n\"a \\\"quoted\\\" \\\\ word\"\n\"\\x89PNG\\x0D\\x0A\"\n",
            (new DictionaryWriter)->write('tokens', ['RATL', 'a "quoted" \\ word', "\x89PNG\r\n"]),
        );
    }

    public function testWritesWhatDictionaryParserReads(): void
    {
        $tokens = ['RATL', 'a "quoted" \\ word', "\x89PNG\r\n\x00\xFF", '# not a comment', 'name="value"'];

        $this->assertSame($tokens, (new DictionaryParser)->parse((new DictionaryWriter)->write('tokens', $tokens)));
    }
}
