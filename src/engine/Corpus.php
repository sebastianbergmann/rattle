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

use function count;
use function file_put_contents;
use function hash;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function unlink;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Encoder;

/**
 * The inputs that produced features that no input before them produced, and
 * the set of known features.
 *
 * An input is added when it produced at least one feature that is new.
 *
 * When the corpus has a directory, each entry is stored there in the format
 * "choice-sequence v1", in a file whose name is the hexadecimal SHA-256 of
 * the encoded choices.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class Corpus
{
    private readonly ?string $directory;

    /**
     * @var list<CorpusEntry>
     */
    private array $entries = [];

    /**
     * @var array<int, true>
     */
    private array $features = [];

    /**
     * @return non-empty-string
     */
    public static function fileNameOf(string $encoded): string
    {
        return hash('sha256', $encoded);
    }

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory;
    }

    /**
     * Adds the input when it produced at least one feature that is new, and
     * returns the entry, or null.
     *
     * @param list<int> $features
     *
     * @throws CorpusException
     */
    public function add(ChoiceSequence $sequence, array $features): ?CorpusEntry
    {
        $newFeatures = [];

        foreach ($features as $feature) {
            if (!isset($this->features[$feature])) {
                $this->features[$feature] = true;
                $newFeatures[]            = $feature;
            }
        }

        if ($newFeatures === []) {
            return null;
        }

        $entry = new CorpusEntry($sequence, $newFeatures, count($this->entries));

        $this->entries[] = $entry;

        $this->store($sequence);

        return $entry;
    }

    /**
     * Removes the input with the given choices from the directory of the
     * corpus, when it is stored there under the name that the corpus gives
     * it, and returns whether it was removed. Files that were stored under
     * another name are left alone.
     *
     * @param list<non-negative-int> $choices
     *
     * @throws CorpusException
     */
    public function remove(array $choices): bool
    {
        if ($this->directory === null) {
            return false;
        }

        $path = $this->directory . '/' . self::fileNameOf((new Encoder)->encode(new ChoiceSequence($choices)));

        if (!is_file($path)) {
            return false;
        }

        if (!@unlink($path)) {
            throw new CorpusException(sprintf('Cannot remove the corpus entry "%s"', $path));
        }

        return true;
    }

    /**
     * @return list<CorpusEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return non-negative-int
     */
    public function numberOfEntries(): int
    {
        return count($this->entries);
    }

    /**
     * @return non-negative-int
     */
    public function numberOfFeatures(): int
    {
        return count($this->features);
    }

    /**
     * @throws CorpusException
     */
    private function store(ChoiceSequence $sequence): void
    {
        if ($this->directory === null) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw new CorpusException(sprintf('Cannot create the corpus directory "%s"', $this->directory));
        }

        $encoded = (new Encoder)->encode(new ChoiceSequence($sequence->choices()));
        $path    = $this->directory . '/' . self::fileNameOf($encoded);

        if (!is_file($path) && @file_put_contents($path, $encoded) === false) {
            throw new CorpusException(sprintf('Cannot write the corpus entry "%s"', $path));
        }
    }
}
