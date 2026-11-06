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

/**
 * The basic blocks of a file that was instrumented, with the lines on which
 * their code starts, in the order of their offsets, and the number of lines
 * of the file, so that the edges that inputs take can be located in the
 * code.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class BlockMap
{
    /**
     * @var non-empty-string
     */
    private string $file;

    /**
     * @var non-negative-int
     */
    private int $numberOfLines;

    /**
     * @var list<array{0: positive-int, 1: positive-int}>
     */
    private array $blocks;

    /**
     * @param non-empty-string                              $file
     * @param non-negative-int                              $numberOfLines
     * @param list<array{0: positive-int, 1: positive-int}> $blocks        the index of each block and the line on which its code starts
     */
    public function __construct(string $file, int $numberOfLines, array $blocks)
    {
        $this->file          = $file;
        $this->numberOfLines = $numberOfLines;
        $this->blocks        = $blocks;
    }

    /**
     * @return non-empty-string
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return non-negative-int
     */
    public function numberOfLines(): int
    {
        return $this->numberOfLines;
    }

    /**
     * The index of each block and the line on which its code starts.
     *
     * @return list<array{0: positive-int, 1: positive-int}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * The same blocks, of the file under another path.
     *
     * @param non-empty-string $file
     */
    public function withFile(string $file): self
    {
        return new self($file, $this->numberOfLines, $this->blocks);
    }

    /**
     * @return array{file: non-empty-string, lines: non-negative-int, blocks: list<array{0: positive-int, 1: positive-int}>}
     */
    public function asArray(): array
    {
        return [
            'file'   => $this->file,
            'lines'  => $this->numberOfLines,
            'blocks' => $this->blocks,
        ];
    }
}
