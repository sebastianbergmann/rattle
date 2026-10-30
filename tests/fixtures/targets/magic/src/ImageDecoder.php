<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\magic;

use function intdiv;
use function ord;
use function strlen;
use function substr;

/**
 * Decodes images that start with the magic header "RATL", followed by a
 * scale. A scale of 0 leads to a division by zero.
 */
final class ImageDecoder
{
    /**
     * @return array{scale: int, pixels: string}
     */
    public function decode(string $data): array
    {
        if (substr($data, 0, 4) !== 'RATL') {
            throw new ParseException('Not a RATL image');
        }

        if (strlen($data) < 5) {
            throw new ParseException('Missing scale');
        }

        return [
            'scale'  => intdiv(1024, ord($data[4])),
            'pixels' => substr($data, 5),
        ];
    }
}
