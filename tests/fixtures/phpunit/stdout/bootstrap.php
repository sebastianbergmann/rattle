<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
// writes to the standard output stream of the process that boots the test
// suite, on which a worker writes its frames
\fwrite(\STDOUT, 'not a frame' . \PHP_EOL);
