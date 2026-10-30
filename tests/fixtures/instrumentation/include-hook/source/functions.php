<?php declare(strict_types=1);
namespace SebastianBergmann\Rattle\Fixture\IncludeHook;

function classify(int $x): string
{
    if ($x > 0) {
        return 'positive';
    }

    return 'not positive';
}
