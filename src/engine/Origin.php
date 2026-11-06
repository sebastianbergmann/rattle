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
 * Where an input that the engine submitted came from: it is an input to
 * start with, it was generated from a fresh seed, or it was mutated from a
 * corpus entry with the mutations that are named.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class Origin
{
    public const string INITIAL   = 'initial';
    public const string GENERATED = 'generated';
    public const string MUTATED   = 'mutated';

    /**
     * @var self::GENERATED|self::INITIAL|self::MUTATED
     */
    private string $kind;

    /**
     * @var ?non-negative-int
     */
    private ?int $parent;

    /**
     * @var list<non-empty-string>
     */
    private array $mutations;

    public static function initial(): self
    {
        return new self(self::INITIAL, null, []);
    }

    public static function generated(): self
    {
        return new self(self::GENERATED, null, []);
    }

    /**
     * @param non-negative-int       $parent    the index of the corpus entry that the input was mutated from
     * @param list<non-empty-string> $mutations the names of the mutations that were applied
     */
    public static function mutated(int $parent, array $mutations): self
    {
        return new self(self::MUTATED, $parent, $mutations);
    }

    /**
     * @param self::GENERATED|self::INITIAL|self::MUTATED $kind
     * @param ?non-negative-int                           $parent
     * @param list<non-empty-string>                      $mutations
     */
    private function __construct(string $kind, ?int $parent, array $mutations)
    {
        $this->kind      = $kind;
        $this->parent    = $parent;
        $this->mutations = $mutations;
    }

    /**
     * @return self::GENERATED|self::INITIAL|self::MUTATED
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The index of the corpus entry that the input was mutated from.
     *
     * @return ?non-negative-int
     */
    public function parent(): ?int
    {
        return $this->parent;
    }

    /**
     * The names of the mutations that were applied, such as AddToken.
     *
     * @return list<non-empty-string>
     */
    public function mutations(): array
    {
        return $this->mutations;
    }
}
