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

use function array_is_list;
use function class_exists;
use function is_array;
use function sprintf;
use Closure;
use PHPUnit\Runner\PropertyBased\PropertyRunner;
use Throwable;

/**
 * Runs the examples of the properties of a PHPUnit test suite with PHPUnit's
 * API for this, PHPUnit\Runner\PropertyBased\PropertyRunner. The API is in
 * the PHPUnit namespace, which PHPUnit's PHAR does not prefix, and it passes
 * only data, so it is called in the same way whether PHPUnit is installed
 * with Composer or used from its PHAR. What it returns is checked like data
 * from another process.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class PhpunitApi
{
    private PropertyRunner $runner;

    /**
     * Loads the configuration file of PHPUnit and its bootstrap script, and
     * builds the test suite.
     *
     * @param ?non-empty-string $configurationFile null to look for one in the working directory as PHPUnit does
     * @param ?non-empty-string $filter            the filter of PHPUnit's --filter option, null for all tests
     *
     * @throws WorkerException
     */
    public static function boot(?string $configurationFile, ?string $filter): self
    {
        if (!class_exists(PropertyRunner::class)) {
            throw new WorkerException(
                sprintf(
                    'Class %s does not exist: fuzzing the properties of a PHPUnit test suite needs a version of PHPUnit that provides it',
                    PropertyRunner::class,
                ),
            );
        }

        try {
            return new self(PropertyRunner::boot($configurationFile, $filter));
        } catch (Throwable $t) {
            throw new WorkerException(sprintf('PHPUnit cannot be booted: %s', $t->getMessage()), 0, $t);
        }
    }

    private function __construct(PropertyRunner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * The test IDs of the properties of the test suite.
     *
     * @throws ProtocolException
     * @throws WorkerException
     *
     * @return list<non-empty-string>
     */
    public function properties(): array
    {
        $properties = $this->call(fn (): mixed => $this->runner->properties());

        if (!is_array($properties) || !array_is_list($properties)) {
            throw new ProtocolException('Invalid list of properties');
        }

        $ids = [];

        foreach ($properties as $property) {
            $ids[] = ProtocolData::nonEmptyString(ProtocolData::array($property)['id'] ?? null);
        }

        return $ids;
    }

    /**
     * @param non-empty-string       $id
     * @param list<non-negative-int> $choices
     * @param positive-int           $timeLimit    in seconds
     * @param bool                   $exportValues whether PHPUnit exports the arguments, the drawn values, and the steps of the example
     *
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function replay(string $id, array $choices, int $timeLimit, bool $exportValues): PropertyExample
    {
        return PropertyExample::fromArray($this->call(fn (): mixed => $this->runner->replay($id, $choices, $timeLimit, $exportValues)));
    }

    /**
     * @param non-empty-string $id
     * @param positive-int     $timeLimit    in seconds
     * @param bool             $exportValues whether PHPUnit exports the arguments, the drawn values, and the steps of the example
     *
     * @throws ProtocolException
     * @throws WorkerException
     */
    public function generate(string $id, int $seed, int $timeLimit, bool $exportValues): PropertyExample
    {
        return PropertyExample::fromArray($this->call(fn (): mixed => $this->runner->generate($id, $seed, $timeLimit, $exportValues)));
    }

    /**
     * The API raises an exception only when it is used wrongly or when the
     * property is invalid, not when an example fails.
     *
     * @param Closure(): mixed $call
     *
     * @throws WorkerException
     */
    private function call(Closure $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $t) {
            throw new WorkerException(sprintf('PHPUnit cannot run the example: %s', $t->getMessage()), 0, $t);
        }
    }
}
