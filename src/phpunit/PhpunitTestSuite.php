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
 * The PHPUnit test suite whose properties are fuzzed: the configuration file
 * of PHPUnit, and the filter of its --filter option.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PhpunitTestSuite
{
    /**
     * @var ?non-empty-string
     */
    private ?string $configurationFile;

    /**
     * @var ?non-empty-string
     */
    private ?string $filter;

    /**
     * @param ?non-empty-string $configurationFile null to look for one in the working directory as PHPUnit does
     * @param ?non-empty-string $filter            null for all tests
     */
    public function __construct(?string $configurationFile, ?string $filter)
    {
        $this->configurationFile = $configurationFile;
        $this->filter            = $filter;
    }

    /**
     * @return ?non-empty-string
     */
    public function configurationFile(): ?string
    {
        return $this->configurationFile;
    }

    /**
     * @return ?non-empty-string
     */
    public function filter(): ?string
    {
        return $this->filter;
    }
}
