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

use function is_a;
use function preg_match;
use function sprintf;
use Closure;
use Throwable;

/**
 * A fuzz target: a named closure that is run with one input after another.
 * A target file returns a target.
 *
 * A bytes target takes one binary string. A structured target draws primitive
 * values from an Input object. Any throwable that is thrown by the closure is
 * a finding, unless it is an instance of one of the classes that are declared
 * as expected with expect().
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Target
{
    /**
     * @var non-empty-string
     */
    private string $name;

    /**
     * @var Closure(Input): void
     */
    private Closure $test;

    /**
     * @var ?positive-int
     */
    private ?int $maximumLength;

    /**
     * @var list<class-string<Throwable>>
     */
    private array $expected;

    /**
     * @param non-empty-string      $name      names the target's directories, must match [a-z0-9-]+
     * @param Closure(string): void $test
     * @param positive-int          $maxLength
     *
     * @throws InvalidTargetException
     */
    public static function bytes(string $name, Closure $test, int $maxLength): self
    {
        if ($maxLength < 1) {
            throw new InvalidTargetException(
                sprintf(
                    'The maximum length of the target "%s" must be positive, %d given',
                    $name,
                    $maxLength,
                ),
            );
        }

        return new self(
            $name,
            static function (Input $input) use ($test, $maxLength): void
            {
                $test($input->bytes($maxLength));
            },
            $maxLength,
            [],
        );
    }

    /**
     * @param non-empty-string     $name names the target's directories, must match [a-z0-9-]+
     * @param Closure(Input): void $test
     *
     * @throws InvalidTargetException
     */
    public static function structured(string $name, Closure $test): self
    {
        return new self($name, $test, null, []);
    }

    /**
     * @param Closure(Input): void          $test
     * @param ?positive-int                 $maximumLength
     * @param list<class-string<Throwable>> $expected
     *
     * @throws InvalidTargetException
     */
    private function __construct(string $name, Closure $test, ?int $maximumLength, array $expected)
    {
        if (preg_match('/\A[a-z0-9-]+\z/', $name) !== 1) {
            throw new InvalidTargetException(
                sprintf(
                    'The name "%s" of a target must be non-empty and consist of lowercase letters, digits, and hyphens only',
                    $name,
                ),
            );
        }

        $this->name          = $name;
        $this->test          = $test;
        $this->maximumLength = $maximumLength;
        $this->expected      = $expected;
    }

    /**
     * Declares documented failure modes: throwables that are instances of
     * these classes, including their subclasses, are not findings.
     *
     * @param class-string<Throwable> ...$classes
     *
     * @throws InvalidTargetException
     */
    public function expect(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (!is_a($class, Throwable::class, true)) {
                throw new InvalidTargetException(
                    sprintf(
                        'The expected class "%s" of the target "%s" is not a throwable',
                        $class,
                        $this->name,
                    ),
                );
            }
        }

        return new self(
            $this->name,
            $this->test,
            $this->maximumLength,
            [...$this->expected, ...$classes],
        );
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @phpstan-assert-if-true positive-int $this->maximumLength()
     */
    public function isBytesTarget(): bool
    {
        return $this->maximumLength !== null;
    }

    /**
     * The maximum length of the string that a bytes target takes, null for a
     * structured target.
     *
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @return ?positive-int
     */
    public function maximumLength(): ?int
    {
        return $this->maximumLength;
    }

    /**
     * The number of choices that every input of a bytes target may need,
     * null for a structured target.
     *
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @return ?positive-int
     */
    public function requiredNumberOfChoices(): ?int
    {
        if ($this->maximumLength === null) {
            return null;
        }

        return 2 * $this->maximumLength + 1;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     *
     * @return list<class-string<Throwable>>
     */
    public function expected(): array
    {
        return $this->expected;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     */
    public function isExpected(Throwable $throwable): bool
    {
        foreach ($this->expected as $class) {
            if ($throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @internal This method is not covered by the backward compatibility promise for sebastian/rattle
     */
    public function run(Input $input): void
    {
        ($this->test)($input);
    }
}
