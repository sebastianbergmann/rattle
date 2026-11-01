<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Fixture\Targets\deserialization;

use function is_array;
use function unserialize;

/**
 * Decodes session data from a cookie with unserialize(), without restricting
 * the classes whose objects it creates.
 */
final class SessionDecoder
{
    /**
     * @return array<mixed>
     */
    public function decode(string $data): array
    {
        $session = @unserialize($data);

        return is_array($session) ? $session : [];
    }
}
