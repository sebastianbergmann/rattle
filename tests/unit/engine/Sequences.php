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

use Closure;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\ChoiceSource;

/**
 * Creates choice sequences with spans and maxima for tests, as Input draws
 * them.
 */
final readonly class Sequences
{
    /**
     * @param non-negative-int $maximumLength
     */
    public static function ofBytes(string $bytes, int $maximumLength = 100): ChoiceSequence
    {
        return self::drawn(
            BytesEncoding::choicesFor($bytes, $maximumLength),
            static function (Input $input) use ($maximumLength): void
            {
                $input->bytes($maximumLength);
            },
        );
    }

    /**
     * @param list<non-negative-int> $choices
     * @param Closure(Input): void   $draw
     */
    public static function drawn(array $choices, Closure $draw): ChoiceSequence
    {
        $source = ChoiceSource::replay(new ChoiceSequence($choices));

        $draw(new Input($source, false));

        return $source->sequence();
    }
}
