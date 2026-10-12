<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Internal;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Internal\HttpProtocol;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;

use function str_repeat;
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

    /**
     * The package size leaves room for the head, a small head must not allow a larger body
     */
    #[TestWith(["Content-Length: 1025\r\nExpect: 100-continue\r\n\r\n", 0], 'content-length')]
    #[TestWith(["Transfer-Encoding: chunked\r\n\r\n401\r\n", 1020], 'chunked')] // an incomplete chunk of 1025 bytes
    public function testBodyLimit(string $head, int $bodyLength): void
    {
        $socket = $this->socket(maxBodySize: 1024);
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\n" . $head . str_repeat('a', $bodyLength);

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        // no 100 (Continue) for a body that is too large
        self::assertStringStartsWith('HTTP/1.1 413 ', $socket->read());
    }

    public function testBodyAtTheLimit(): void
    {
        $socket = $this->socket(maxBodySize: 1024);
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1024\r\n\r\n" . str_repeat('a', 1024);

        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read());
    }

    #[TestWith(["FFFFFFFF\r\n", 'aaaaaaaaaa'], 'large chunk')]
    #[TestWith(["1\r\na\r\n0\r\n", "X-Trailer: a\r\n"], 'endless trailers')]
    public function testChunkedTooLarge(string $start, string $more): void
    {
        $socket = new SocketConnection();
        $socket->connection->maxPackageSize = 1024;
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n" . $start;

        while (strlen($buffer) <= 1024) {
            self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
            self::assertSame('', $socket->read());
            $buffer .= $more;
        }

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertStringStartsWith('HTTP/1.1 413 ', $socket->read());
    }

    #[TestWith(["5;\r\nhello\r\n0\r\n\r\n"], 'bare semicolon')]
    #[TestWith(["5;bad[=x\r\nhello\r\n0\r\n\r\n"], 'invalid token')]
    #[TestWith(["5;\nhello\r\n0\r\n\r\n"], 'lf in extension')]
    #[TestWith(["5;\x00ext\r\nhello\r\n0\r\n\r\n"], 'nul in extension')]
    #[TestWith(["5;a\rX\r\nhello\r\n0\r\n\r\n"], 'cr in extension')]
    #[TestWith(["5;a=\"b\r\nhello\r\n0\r\n\r\n"], 'unterminated quoted string')]
    #[TestWith(["5\r\nhello\r\n0\r\n\n"], 'lf after the last chunk')]
    #[TestWith(["5\r\nhello\r\n0\r\nX-A: a\nb\r\n\r\n"], 'lf in trailer')]
    #[TestWith(["5\r\nhello\r\n0\r\nX A: b\r\n\r\n"], 'invalid trailer name')]
    #[TestWith(["5\r\nhello\r\n5\n"], 'lf in an incomplete line')]
    #[TestWith(["5\r\nhello\r\n\x01\r"], 'control character before a cr')]
    public function testInvalidChunks(string $body): void
    {
        $socket = new SocketConnection();
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n" . $body;

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertStringStartsWith('HTTP/1.1 400 ', $socket->read());
    }

    #[TestWith(["5;a\r\nhello\r\n0\r\n\r\n"], 'extension')]
    #[TestWith(["5;a = b ; c=\"d \\\" e\"\r\nhello\r\n0\r\n\r\n"], 'extensions with values')]
    #[TestWith(["5\r\nhello\r\n0\r\nX-A: a b\r\n\r\n"], 'trailer')]
    #[TestWith(["5\r\nhello\r\n0\r\n\r\nGET /\x00"], 'followed by another request')]
    public function testCompleteChunks(string $body): void
    {
        $socket = new SocketConnection();
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n" . $body;

        self::assertGreaterThan(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read());
    }

    #[TestWith(["5;a\r"], 'incomplete line')]
    #[TestWith(["5\r\nhel"], 'incomplete data')]
    #[TestWith(["5\r\nhello\r"], 'incomplete crlf after the data')]
    public function testIncompleteChunks(string $body): void
    {
        $socket = new SocketConnection();
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n" . $body;

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read(), 'not rejected');
    }

    public function testOtherBodiesAreNotValidatedAsChunks(): void
    {
        $socket = new SocketConnection();
        $body = "{\n\t\"a\": \"\x01\r\"\n}";
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read());
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

    /**
     * Like HttpServer sets it for its connections
     */
    private function socket(int $maxBodySize): SocketConnection
    {
        $socket = new SocketConnection();
        self::assertNotNull($socket->connection->context);
        $socket->connection->context->maxBodySize = $maxBodySize;

        return $socket;
    }
}
