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

use function assert;
use function ksort;
use function strlen;
use function substr;
use function usort;

/**
 * Collects edits of a source text whose offsets refer to the original text,
 * and applies them all at once, so that the offsets of the syntax tree stay
 * valid while the edits are collected.
 *
 * Text that is inserted at the same offset is ordered by its priority, lower
 * first, and then by the order in which it was inserted. A replacement at an
 * offset comes after the insertions there.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class SourceEdits
{
    private readonly string $source;

    /**
     * The insertions by offset.
     *
     * @var array<non-negative-int, list<array{priority: int, text: string}>>
     */
    private array $insertions = [];

    /**
     * The replacements by offset.
     *
     * @var array<non-negative-int, array{length: positive-int, text: string}>
     */
    private array $replacements = [];

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    /**
     * @param non-negative-int $offset
     */
    public function insert(int $offset, string $text, int $priority): void
    {
        $this->insertions[$offset][] = ['priority' => $priority, 'text' => $text];
    }

    /**
     * @param non-negative-int $offset
     * @param positive-int     $length
     */
    public function replace(int $offset, int $length, string $text): void
    {
        assert(!isset($this->replacements[$offset]));

        $this->replacements[$offset] = ['length' => $length, 'text' => $text];
    }

    public function source(): string
    {
        return $this->source;
    }

    public function apply(): string
    {
        $offsets = [];

        foreach ($this->insertions as $offset => $insertions) {
            $offsets[$offset] = true;
        }

        foreach ($this->replacements as $offset => $replacement) {
            $offsets[$offset] = true;
        }

        ksort($offsets);

        $result = '';
        $copied = 0;

        foreach ($offsets as $offset => $ignored) {
            if ($offset < $copied) {
                // within text that was replaced
                continue;
            }

            $result .= substr($this->source, $copied, $offset - $copied);
            $copied = $offset;

            $insertions = $this->insertions[$offset] ?? [];

            // the sort is stable, so insertions with the same priority keep
            // the order in which they were made
            usort($insertions, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

            foreach ($insertions as $insertion) {
                $result .= $insertion['text'];
            }

            if (isset($this->replacements[$offset])) {
                $result .= $this->replacements[$offset]['text'];
                $copied += $this->replacements[$offset]['length'];
            }
        }

        return $result . substr($this->source, $copied, strlen($this->source) - $copied);
    }
}
