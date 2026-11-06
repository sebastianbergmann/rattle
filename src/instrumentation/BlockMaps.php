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
 * The blocks of the files that a worker instrumented (see BlockMap), of which
 * the ones that were not reported yet are reported with the next result of
 * an input that asks for its coverage. A file that is instrumented again,
 * because it is included again, is reported once.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class BlockMaps
{
    /**
     * @var array<string, true>
     */
    private array $files = [];

    /**
     * @var list<BlockMap>
     */
    private array $unreported = [];

    public function add(BlockMap $map): void
    {
        if (isset($this->files[$map->file()])) {
            return;
        }

        $this->files[$map->file()] = true;
        $this->unreported[]        = $map;
    }

    /**
     * The maps of the files that were instrumented since this was last
     * called.
     *
     * @return list<BlockMap>
     */
    public function takeUnreported(): array
    {
        $unreported = $this->unreported;

        $this->unreported = [];

        return $unreported;
    }
}
