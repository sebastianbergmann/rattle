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

use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_dir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

#[CoversClass(Corpus::class)]
#[CoversClass(CorpusEntry::class)]
#[UsesClass(Candidate::class)]
#[UsesClass(CorpusException::class)]
#[Small]
final class CorpusTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null && is_dir($this->directory)) {
            foreach ((array) scandir($this->directory) as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->directory . '/' . $file);
                }
            }

            rmdir($this->directory);
        }
    }

    public function testAddsAnInputThatProducedANewFeature(): void
    {
        $corpus = new Corpus;

        $this->assertTrue($corpus->isEmpty());

        $first = $corpus->add(new ChoiceSequence([1, 2]), [10, 20]);

        $this->assertNotNull($first);
        $this->assertSame([10, 20], $first->newFeatures());
        $this->assertSame(0, $first->index());
        $this->assertSame(2, $first->length());
        $this->assertSame([1, 2], $first->sequence()->choices());

        $this->assertNull($corpus->add(new ChoiceSequence([3]), [10]));
        $this->assertNull($corpus->add(new ChoiceSequence([3]), []));

        $second = $corpus->add(new ChoiceSequence([4]), [20, 30]);

        $this->assertNotNull($second);
        $this->assertSame([30], $second->newFeatures());
        $this->assertSame(1, $second->index());

        $this->assertFalse($corpus->isEmpty());
        $this->assertSame(2, $corpus->numberOfEntries());
        $this->assertSame(3, $corpus->numberOfFeatures());
        $this->assertSame([$first, $second], $corpus->entries());
    }

    public function testAnEntryHasACopyOfItsCandidateForEachMutation(): void
    {
        $entry = (new Corpus)->add(new ChoiceSequence([1, 2], [], [3, 4]), [10]);

        $this->assertNotNull($entry);

        $candidate = $entry->candidate();

        $candidate->set(0, 3);
        $candidate->delete(1, 1);

        $this->assertSame([3], $candidate->choices());
        $this->assertSame([1, 2], $entry->candidate()->choices());
        $this->assertSame(4, $entry->candidate()->maximumAt(1));
        $this->assertNotSame($entry->candidate(), $entry->candidate());
    }

    public function testStoresEntriesInTheFormatOfChoiceSequencesNamedAfterTheirHash(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-corpus-' . uniqid();

        $corpus = new Corpus($this->directory);

        $corpus->add(new ChoiceSequence([1, 2]), [10]);
        $corpus->add(new ChoiceSequence([1, 2]), [11]);

        $encoded = "choice-sequence v1\n1\n2\n";

        $this->assertSame(hash('sha256', $encoded), Corpus::fileNameOf($encoded));
        $this->assertSame($encoded, file_get_contents($this->directory . '/' . hash('sha256', $encoded)));
        $this->assertCount(3, (array) scandir($this->directory));
    }

    public function testRemovesAnInputThatItStored(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-corpus-' . uniqid();

        $corpus = new Corpus($this->directory);

        $corpus->add(new ChoiceSequence([1, 2]), [10]);

        file_put_contents($this->directory . '/other-name', "choice-sequence v1\n3\n");

        $this->assertTrue($corpus->remove([1, 2]));
        $this->assertFalse($corpus->remove([1, 2]));
        $this->assertFalse($corpus->remove([3]));
        $this->assertSame(['.', '..', 'other-name'], scandir($this->directory));
    }

    public function testReplacesAnEntryWithAnInputThatHasFewerChoices(): void
    {
        $this->directory = sys_get_temp_dir() . '/rattle-corpus-' . uniqid();

        $corpus = new Corpus($this->directory);

        $corpus->add(new ChoiceSequence([1, 2, 3]), [10, 20]);
        $corpus->add(new ChoiceSequence([4]), [30]);

        $changes = $corpus->numberOfChanges();

        $this->assertFalse($corpus->replace(0, new ChoiceSequence([5, 6, 7])));
        $this->assertSame($changes, $corpus->numberOfChanges());

        $this->assertTrue($corpus->replace(0, new ChoiceSequence([1, 2])));

        $entry = $corpus->entries()[0] ?? null;

        $this->assertNotNull($entry);
        $this->assertSame([1, 2], $entry->sequence()->choices());
        $this->assertSame([10, 20], $entry->newFeatures());
        $this->assertSame(0, $entry->index());
        $this->assertSame(2, $corpus->numberOfEntries());
        $this->assertSame($changes + 1, $corpus->numberOfChanges());

        // the input that replaced the entry is stored instead of it
        $this->assertFileExists($this->directory . '/' . Corpus::fileNameOf("choice-sequence v1\n1\n2\n"));
        $this->assertFileDoesNotExist($this->directory . '/' . Corpus::fileNameOf("choice-sequence v1\n1\n2\n3\n"));
    }

    public function testRemovesNothingWithoutADirectory(): void
    {
        $this->assertFalse((new Corpus)->remove([1, 2]));
    }

    public function testCannotStoreEntriesWhereNoDirectoryCanBeCreated(): void
    {
        $this->expectException(CorpusException::class);

        (new Corpus('/dev/null/corpus'))->add(new ChoiceSequence([1]), [1]);
    }
}
