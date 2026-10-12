<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Semperton\Workerman\PushStream;

final class PushStreamTest extends TestCase
{
    public function testBuffered(): void
    {
        $stream = new PushStream();
        $stream->push('ab');
        $stream->push('cd');

        self::assertNull($stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertSame('a', $stream->read(1));
        self::assertSame('bcd', $stream->getContents());
        // it doesn't wait for more
        self::assertSame('', $stream->read(1));
        self::assertFalse($stream->eof());

        $stream->end();
        self::assertTrue($stream->eof());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));
    }

    public function testNotWritable(): void
    {
        $this->expectException(RuntimeException::class);

        (new PushStream())->write('a');
    }

    public function testNotSeekable(): void
    {
        $this->expectException(RuntimeException::class);

        (new PushStream())->rewind();
    }

    public function testOnCloseAfterClose(): void
    {
        $stream = new PushStream();
        $stream->close();

        $called = false;
        $stream->onClose(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
        self::assertFalse($stream->push('a'));
    }

    public function testListenerError(): void
    {
        $called = false;
        $stream = new PushStream();
        $stream->onClose(static fn() => throw new RuntimeException('Failed'));
        $stream->onClose(static fn() => throw new RuntimeException('Failed again'));
        $stream->onClose(static function () use (&$called): void {
            $called = true;
        });

        try {
            $stream->close();
            self::fail('The error is thrown');
        } catch (RuntimeException $exception) {
            // the first one
            self::assertSame('Failed', $exception->getMessage());
        }

        // e.g. a timer that keeps pushing would never be removed
        self::assertTrue($called);
    }

    public function testDetach(): void
    {
        $closed = false;
        $stream = new PushStream();
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        self::assertNull($stream->detach());
        self::assertTrue($closed);
    }
}
