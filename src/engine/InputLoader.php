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
use function is_dir;
use function is_file;
use function scandir;
use function sort;
use function sprintf;
use SebastianBergmann\ChoiceSequence\Decoder;
use SebastianBergmann\ChoiceSequence\InvalidChoiceSequenceException;

/**
 * Loads the inputs that fuzzing starts with: choice sequences that are
 * stored in the format "choice-sequence v1", and raw files that a bytes
 * target takes as they are. The files of a directory are loaded in the order
 * of their names.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class InputLoader
{
    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @return list<list<non-negative-int>>
     */
    public function choiceSequencesIn(string $directory): array
    {
        $inputs = [];

        foreach ($this->filesIn($directory) as $path) {
            try {
                $inputs[] = (new Decoder)->decode((string) file_get_contents($path))->choices();
            } catch (InvalidChoiceSequenceException $e) {
                $this->warnings[] = sprintf('%s is not a valid choice sequence: %s', $path, $e->getMessage());
            }
        }

        return $inputs;
    }

    /**
     * @param positive-int $maximumLength
     *
     * @return list<list<non-negative-int>>
     */
    public function rawFilesIn(string $directory, int $maximumLength): array
    {
        $inputs = [];

        foreach ($this->filesIn($directory) as $path) {
            $inputs[] = BytesEncoding::choicesFor((string) file_get_contents($path), $maximumLength);
        }

        return $inputs;
    }

    /**
     * What could not be loaded.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        $names = scandir($directory);

        if ($names === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        foreach ($names as $name) {
            if ($name[0] === '.') {
                continue;
            }

            if (is_file($directory . '/' . $name)) {
                $files[] = $directory . '/' . $name;
            }
        }

        sort($files);

        return $files;
    }
}
