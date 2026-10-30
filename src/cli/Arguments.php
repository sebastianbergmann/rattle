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

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Arguments
{
    /**
     * @var ?non-empty-string
     */
    private ?string $command;
    private bool $help;
    private bool $version;

    /**
     * @param ?non-empty-string $command
     */
    public function __construct(?string $command, bool $help, bool $version)
    {
        $this->command = $command;
        $this->help    = $help;
        $this->version = $version;
    }

    /**
     * @return ?non-empty-string
     */
    public function command(): ?string
    {
        return $this->command;
    }

    public function help(): bool
    {
        return $this->help;
    }

    public function version(): bool
    {
        return $this->version;
    }
}
