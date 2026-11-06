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

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_split;
use function strlen;
use function substr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Frame::class)]
#[CoversClass(FrameBuffer::class)]
#[CoversClass(FrameStream::class)]
#[UsesClass(ProtocolException::class)]
#[Small]
final class FrameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function payloadsThatAreNotJsonObjects(): array
    {
        return [
            'not JSON'                   => ['not json'],
            'JSON that is not an object' => ['42'],
        ];
    }

    public function testEncodesAMessageAsJsonWithALengthPrefix(): void
    {
        $frame = Frame::encode(['type' => 'ready']);

        $this->assertSame(pack('N', 16) . '{"type":"ready"}', $frame);
        $this->assertSame(['type' => 'ready'], Frame::decode(substr($frame, 4)));
    }

    public function testEncodesStringsThatAreNotValidUtf8WithTheReplacementCharacter(): void
    {
        $this->assertSame(['message' => "a\u{FFFD}b"], Frame::decode(substr(Frame::encode(['message' => "a\xFFb"]), 4)));
    }

    #[DataProvider('payloadsThatAreNotJsonObjects')]
    public function testRejectsAPayloadThatIsNotAJsonObject(string $payload): void
    {
        $this->expectException(ProtocolException::class);

        Frame::decode($payload);
    }

    public function testCollectsFramesFromChunks(): void
    {
        $bytes  = Frame::encode(['n' => 1]) . Frame::encode(['n' => 2]) . Frame::encode(['n' => 3]);
        $buffer = new FrameBuffer;
        $frames = [];

        foreach (str_split($bytes, 5) as $chunk) {
            $buffer->append($chunk);

            foreach ($buffer->frames() as $frame) {
                $frames[] = $frame;
            }
        }

        $this->assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $frames);
        $this->assertTrue($buffer->isEmpty());
    }

    public function testRejectsAFrameThatIsTooLarge(): void
    {
        $buffer = new FrameBuffer;

        $buffer->append(pack('N', Frame::MAXIMUM_LENGTH + 1));

        $this->expectException(ProtocolException::class);

        $buffer->frames();
    }

    public function testReadsAndWritesFramesOnStreams(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        $frames = new FrameStream($stream, $stream);

        $frames->write(['n' => 1]);
        $frames->write(['n' => 2]);

        rewind($stream);

        $this->assertSame(['n' => 1], $frames->read());
        $this->assertSame(['n' => 2], $frames->read());
        $this->assertNull($frames->read());
    }

    public function testRejectsAStreamThatEndsWithinAFrame(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        $frame = Frame::encode(['n' => 1]);

        fwrite($stream, substr($frame, 0, strlen($frame) - 1));
        rewind($stream);

        $this->expectException(ProtocolException::class);

        (new FrameStream($stream, $stream))->read();
    }

    public function testRejectsAnEmptyFrameOnAStream(): void
    {
        $stream = fopen('php://memory', 'w+');

        $this->assertIsResource($stream);

        fwrite($stream, pack('N', 0));
        rewind($stream);

        $this->expectException(ProtocolException::class);

        (new FrameStream($stream, $stream))->read();
    }

    public function testStopsWritingAFrameWhenTheStreamCannotBeWritten(): void
    {
        $stream = fopen('php://memory', 'r');

        $this->assertIsResource($stream);

        (new FrameStream($stream, $stream))->write(['n' => 1]);

        $this->assertNull((new FrameStream($stream, $stream))->read());
    }
}
