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

use function assert;
use function sprintf;
use SebastianBergmann\ChoiceSequence\FailureSignature;

/**
 * How an input made the target fail, as data that a worker sends to the
 * supervisor and that is stored with a finding. The failure signature is
 * built from it with the named constructor that matches its kind.
 *
 * A failure of the kind "exit" is reported by a worker whose process ends
 * while an input is executed, for instance because the target calls exit().
 * The supervisor turns it into a failure that is described by the exit status
 * of the process (see exited()).
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Failure
{
    public const string THROWABLE   = 'throwable';
    public const string TIMEOUT     = 'timeout';
    public const string DESCRIPTION = 'description';
    public const string EXIT        = 'exit';

    /**
     * @var self::DESCRIPTION|self::EXIT|self::THROWABLE|self::TIMEOUT
     */
    private string $kind;

    /**
     * @var ?class-string
     */
    private ?string $class;

    /**
     * @var ?non-empty-string
     */
    private ?string $location;

    /**
     * @var ?non-empty-string
     */
    private ?string $description;
    private string $message;
    private string $trace;

    /**
     * @param class-string     $class
     * @param non-empty-string $location
     */
    public static function throwable(string $class, string $location, string $message, string $trace): self
    {
        return new self(self::THROWABLE, $class, $location, null, $message, $trace);
    }

    public static function timeout(string $message, string $trace): self
    {
        return new self(self::TIMEOUT, null, null, null, $message, $trace);
    }

    /**
     * @param non-empty-string $description
     */
    public static function description(string $description, string $message, string $trace): self
    {
        return new self(self::DESCRIPTION, null, null, $description, $message, $trace);
    }

    public static function exit(): self
    {
        return new self(self::EXIT, null, null, null, '', '');
    }

    /**
     * The failure of an input during which the process of a worker ended
     * without reporting a result.
     */
    public static function processEnded(?int $signal, ?int $status): self
    {
        if ($signal !== null) {
            return self::description(sprintf('Process terminated by signal %d', $signal), '', '');
        }

        return self::description(sprintf('Process exited with status %d', (int) $status), '', '');
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ProtocolException
     */
    public static function fromArray(array $data): self
    {
        $message = ProtocolData::string($data['message'] ?? null);
        $trace   = ProtocolData::string($data['trace'] ?? null);

        if (($data['kind'] ?? null) === self::THROWABLE) {
            $class = ProtocolData::nonEmptyString($data['class'] ?? null);

            self::assertClassName($class);

            return self::throwable($class, ProtocolData::nonEmptyString($data['location'] ?? null), $message, $trace);
        }

        return match ($data['kind'] ?? null) {
            self::TIMEOUT     => self::timeout($message, $trace),
            self::DESCRIPTION => self::description(ProtocolData::nonEmptyString($data['description'] ?? null), $message, $trace),
            self::EXIT        => self::exit(),
            default           => throw new ProtocolException('Invalid kind of failure'),
        };
    }

    /**
     * @param self::DESCRIPTION|self::EXIT|self::THROWABLE|self::TIMEOUT $kind
     * @param ?class-string                                              $class
     * @param ?non-empty-string                                          $location
     * @param ?non-empty-string                                          $description
     */
    private function __construct(string $kind, ?string $class, ?string $location, ?string $description, string $message, string $trace)
    {
        $this->kind        = $kind;
        $this->class       = $class;
        $this->location    = $location;
        $this->description = $description;
        $this->message     = $message;
        $this->trace       = $trace;
    }

    /**
     * @return self::DESCRIPTION|self::EXIT|self::THROWABLE|self::TIMEOUT
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The class of a throwable, null for the other kinds of failures.
     *
     * @return ?class-string
     */
    public function className(): ?string
    {
        return $this->class;
    }

    /**
     * The location of a throwable, null for the other kinds of failures.
     *
     * @return ?non-empty-string
     */
    public function location(): ?string
    {
        return $this->location;
    }

    public function isExit(): bool
    {
        return $this->kind === self::EXIT;
    }

    public function isTimeout(): bool
    {
        return $this->kind === self::TIMEOUT;
    }

    /**
     * Turns a failure of the kind "exit" into the failure that is described
     * by the exit status of the process.
     */
    public function exited(int $status): self
    {
        assert($this->kind === self::EXIT);

        return self::processEnded(null, $status);
    }

    public function signature(): FailureSignature
    {
        if ($this->kind === self::THROWABLE) {
            assert($this->class !== null && $this->location !== null);

            return FailureSignature::forThrowable($this->class, $this->location);
        }

        if ($this->kind === self::TIMEOUT) {
            return FailureSignature::forTimeout();
        }

        if ($this->kind === self::EXIT) {
            return FailureSignature::forDescription('Process exited');
        }

        assert($this->description !== null);

        return FailureSignature::forDescription($this->description);
    }

    /**
     * @return non-empty-string
     */
    public function describe(): string
    {
        return $this->signature()->description();
    }

    public function message(): string
    {
        return $this->message;
    }

    public function trace(): string
    {
        return $this->trace;
    }

    /**
     * @return array{kind: string, class?: string, location?: string, description?: string, message: string, trace: string}
     */
    public function asArray(): array
    {
        $data = ['kind' => $this->kind];

        if ($this->class !== null) {
            $data['class'] = $this->class;
        }

        if ($this->location !== null) {
            $data['location'] = $this->location;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        $data['message'] = $this->message;
        $data['trace']   = $this->trace;

        return $data;
    }

    /**
     * The class of a throwable is not loaded in the supervisor, where the
     * failure is read from the data that a worker sent, and cannot be checked
     * there.
     *
     * @phpstan-assert class-string $class
     */
    private static function assertClassName(string $class): void
    {
    }
}
