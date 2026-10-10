<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;

/**
 * A stream of unknown size, like a generated or proxied body
 */
final class UnknownSizeStream implements StreamInterface
{
    public function __construct(
        private readonly StreamInterface $stream,
    ) {}

    public function getSize(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->stream->__toString();
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
