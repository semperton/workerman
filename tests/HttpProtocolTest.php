<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Semperton\Workerman\HttpProtocol;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;

use function strlen;

final class HttpProtocolTest extends TestCase
{
    private const CONTINUE = "HTTP/1.1 100 Continue\r\n\r\n";

    public function testContinue(): void
    {
        $socket = new SocketConnection();
        $head = "POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n";

        self::assertSame(strlen($head) + 5, HttpProtocol::input($head, $socket->connection));
        self::assertSame(self::CONTINUE, $socket->read());
    }

    public function testContinueChunked(): void
    {
        $socket = new SocketConnection();
        $head = "POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-Continue\r\nTransfer-Encoding: chunked\r\n\r\n";

        // the length of chunked bodies is unknown until their end
        self::assertSame(0, HttpProtocol::input($head, $socket->connection));
        self::assertSame(self::CONTINUE, $socket->read());
    }

    #[TestWith(["POST / HTTP/1.0\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n"], 'http/1.0')]
    #[TestWith(["POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\nhel"], 'body sent')]
    #[TestWith(["POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\n"], 'not expected')]
    #[TestWith(["GET / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\n\r\n"], 'no body')]
    public function testNoContinue(string $buffer): void
    {
        $socket = new SocketConnection();
        HttpProtocol::input($buffer, $socket->connection);

        self::assertSame('', $socket->read());
    }

    public function testTooLarge(): void
    {
        $socket = new SocketConnection();
        $socket->connection->maxPackageSize = 1024;
        $head = "POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 2048\r\n\r\n";

        self::assertSame(0, HttpProtocol::input($head, $socket->connection));
        self::assertStringStartsWith('HTTP/1.1 413 ', $socket->read());
    }

    public function testHeadStartedAt(): void
    {
        $socket = new SocketConnection();
        $context = $socket->connection->context;
        self::assertNotNull($context);

        self::assertSame(0, HttpProtocol::input("GET / HTTP/1.1\r\nHost: loc", $socket->connection));
        self::assertIsFloat($context->headStartedAt ?? null);

        HttpProtocol::input("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n", $socket->connection);
        self::assertFalse(isset($context->headStartedAt));
    }
}
