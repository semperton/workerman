<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function min;
use function str_repeat;
use function strlen;
use function substr;

use const SEEK_SET;

/**
 * A large body generated while it is read, e.g. larger than the socket buffers of the os, without keeping it in memory
 */
final class RepeatedStream implements StreamInterface
{
    private const BLOCK_SIZE = 64 * 1024;

    private readonly string $block;
    private int $position = 0;

    public function __construct(
        string $pattern,
        private readonly int $size,
    ) {
        $this->block = str_repeat($pattern, (int) (self::BLOCK_SIZE / strlen($pattern)));
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function read(int $length): string
    {
        $data = substr($this->block, 0, min($length, strlen($this->block), $this->size - $this->position));
        $this->position += strlen($data);

        return $data;
    }

    public function eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('The stream is not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('The stream is not seekable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is not writable');
    }

    public function getContents(): string
    {
        throw new RuntimeException('The stream is too large to read at once');
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
