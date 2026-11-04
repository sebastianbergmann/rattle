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
 * One of the targets that are fuzzed together with one pool of workers (see
 * TargetScheduler): a property of a PHPUnit test suite, or a fuzz target.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class FuzzedTarget
{
    /**
     * @var non-empty-string
     */
    private string $name;

    /**
     * @var non-empty-string
     */
    private string $directory;
    private TargetDescription $description;

    /**
     * @var non-empty-string
     */
    private string $reproduction;
    private WorkerConfiguration $configuration;
    private Dictionary $dictionary;

    /**
     * @param non-empty-string    $name          the name that the inputs of the target name, the test ID of a property or the name of a fuzz target
     * @param non-empty-string    $directory     the name of the directories of the corpus and the findings of the target
     * @param non-empty-string    $reproduction  what is passed to "rattle run" to execute an input of the target again
     * @param WorkerConfiguration $configuration the configuration of a worker that executes the inputs of the target only, which minimizes them
     * @param Dictionary          $dictionary    the dictionary that the inputs of the target are mutated with
     */
    public function __construct(string $name, string $directory, TargetDescription $description, string $reproduction, WorkerConfiguration $configuration, Dictionary $dictionary)
    {
        $this->name          = $name;
        $this->directory     = $directory;
        $this->description   = $description;
        $this->reproduction  = $reproduction;
        $this->configuration = $configuration;
        $this->dictionary    = $dictionary;
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return non-empty-string
     */
    public function directory(): string
    {
        return $this->directory;
    }

    public function description(): TargetDescription
    {
        return $this->description;
    }

    /**
     * @return non-empty-string
     */
    public function reproduction(): string
    {
        return $this->reproduction;
    }

    public function configuration(): WorkerConfiguration
    {
        return $this->configuration;
    }

    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }
}
