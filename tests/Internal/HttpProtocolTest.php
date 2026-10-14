<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Internal\HttpProtocol;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;

use function intdiv;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;
use function uniqid;

final class HttpProtocolTest extends TestCase
{
    private const CONTINUE = "HTTP/1.1 100 Continue\r\n\r\n";
    private const CHUNKED = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n";

    #[TestWith(["POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n"], 'expect first')]
    #[TestWith(["POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n"], 'expect last')] // like curl
    public function testContinue(string $head): void
    {
        $socket = new SocketConnection();

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
    #[TestWith(["POST /100-continue HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\n"], 'in the target only')]
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
    #[TestWith(["7ffffffffffffffe\r\n", 'aaaaaaaaaa'], 'chunk size near the int limit')]
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
    #[TestWith(["5\r\nhelloXY"], 'no crlf after the data')]
    #[TestWith(["5\r\nhello\rX0\r\n\r\n"], 'no lf after the data')]
    #[TestWith(["5\r\nhelloX\n0\r\n\r\n"], 'no cr after the data')]
    #[TestWith(["00000000000000005\r\nhello\r\n0\r\n\r\n"], 'size of 17 digits')] // workerman's limit
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
    #[TestWith(["5\r\nh\r\nl"], 'incomplete data with a crlf')] // the data isn't a chunk line
    public function testIncompleteChunks(string $body): void
    {
        $socket = new SocketConnection();
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n" . $body;

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read(), 'not rejected');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chunkedBodies(): iterable
    {
        yield 'chunks' => ["5\r\nhello\r\n1\r\n \r\n5\r\nworld\r\n0\r\n\r\n"];
        yield 'uppercase hex and leading zeros' => ["00A\r\n0123456789\r\n000\r\n\r\n"];
        yield 'extensions' => ["5;a = b ; c=\"d \\\" e\"\r\nhello\r\n0;last\r\n\r\n"];
        yield 'trailers' => ["5\r\nhello\r\n0\r\nX-A: a b\r\nX-B:c:d\r\nX-Empty:\r\n\r\n"];
        yield 'empty' => ["0\r\n\r\n"];
        yield 'data like a chunk line' => ["7\r\n0\r\n\r\n\r\n\r\n0\r\n\r\n"];
        yield 'followed by another request' => ["5\r\nhello\r\n0\r\n\r\nGET / HTTP/1.1\r\n"];
    }

    /**
     * Every read continues where the previous one stopped, the request ends where it ends in one read
     */
    #[DataProvider('chunkedBodies')]
    public function testChunksInParts(string $body): void
    {
        $buffer = self::CHUNKED . $body;
        $length = HttpProtocol::input($buffer, (new SocketConnection())->connection);
        self::assertGreaterThan(0, $length);

        for ($cut = 1; $cut < strlen($buffer); $cut++) {
            $socket = new SocketConnection();
            // the head, a part of the body, the rest
            foreach ([intdiv($cut, 2), $cut] as $part) {
                if ($part > 0 && $part < $length) {
                    self::assertSame(0, HttpProtocol::input(substr($buffer, 0, $part), $socket->connection), "part of $part bytes");
                }
            }
            self::assertSame($length, HttpProtocol::input($buffer, $socket->connection), "cut at $cut");
            self::assertSame('', $socket->read(), "cut at $cut");
            // closes the socket, workerman keeps connections until they are destroyed
            $socket->connection->destroy();
        }
    }

    #[TestWith(["5\r\nhello\r\n5;\r\nworld\r\n0\r\n\r\n"], 'invalid extension')]
    #[TestWith(["5\r\nhello\r\n5\r\nworldX\r\n0\r\n\r\n"], 'data longer than its size')]
    #[TestWith(["5\r\nhello\r\n0\r\nX A: b\r\n\r\n"], 'invalid trailer')]
    #[TestWith(["5\r\nhello\r\n12345678901234567\r\n"], 'size of 17 digits')]
    public function testInvalidChunksInParts(string $body): void
    {
        $buffer = self::CHUNKED . $body;

        for ($cut = 1; $cut < strlen($buffer); $cut++) {
            $socket = new SocketConnection();
            HttpProtocol::input(substr($buffer, 0, $cut), $socket->connection);
            if ($socket->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                self::assertSame(0, HttpProtocol::input($buffer, $socket->connection), "cut at $cut");
            }
            self::assertStringStartsWith('HTTP/1.1 400 ', $socket->read(), "cut at $cut");
            $socket->connection->destroy();
        }
    }

    /**
     * Decoded in linear time, the same request as Workerman's
     */
    #[DataProvider('chunkedBodies')]
    public function testDecodeLikeWorkerman(string $body): void
    {
        $buffer = self::CHUNKED . $body;
        $connection = (new SocketConnection())->connection;
        $workermanConnection = (new SocketConnection())->connection;

        $length = HttpProtocol::input($buffer, $connection);
        self::assertSame($length, Http::input($buffer, $workermanConnection));

        $request = HttpProtocol::decode(substr($buffer, 0, $length), $connection);
        $expected = Http::decode(substr($buffer, 0, $length), $workermanConnection);
        self::assertInstanceOf(Request::class, $request);
        self::assertInstanceOf(Request::class, $expected);

        self::assertSame($expected->rawBuffer(), $request->rawBuffer());
        self::assertSame($expected->trailer(), $request->trailer());
    }

    /**
     * The request may be exactly as large as the package size
     */
    public function testChunkedAtTheLimit(): void
    {
        $chunk = self::CHUNKED . "5\r\nhello\r\n";
        $buffer = $chunk . "0\r\n\r\n";

        // the end of the first chunk is at the limit, the rest may follow
        $socket = new SocketConnection();
        $socket->connection->maxPackageSize = strlen($chunk);
        self::assertSame(0, HttpProtocol::input($chunk, $socket->connection));
        self::assertSame('', $socket->read());

        $socket = new SocketConnection();
        $socket->connection->maxPackageSize = strlen($buffer);
        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read());

        $socket->connection->maxPackageSize = strlen($buffer) - 1;
        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertStringStartsWith('HTTP/1.1 413 ', $socket->read());
    }

    /**
     * A read continues at the line where the previous one stopped, the lines before it aren't read again
     */
    public function testChunksContinueWhereTheyStopped(): void
    {
        $invalid = self::CHUNKED . "X\r\n";
        $buffer = $invalid . "5\r\nhello\r\n0\r\n\r\n";

        $socket = new SocketConnection();
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $socket->connection->context->chunkedAt = [strlen($invalid), false];
        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));

        // at the end of the buffer
        $socket = new SocketConnection();
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $socket->connection->context->chunkedAt = [strlen($invalid), false];
        self::assertSame(0, HttpProtocol::input($invalid, $socket->connection));
        self::assertSame('', $socket->read());
    }

    /**
     * A position beyond the buffer belongs to another request, the body is read from its start
     */
    public function testChunksFromTheStart(): void
    {
        $buffer = self::CHUNKED . "5\r\nhello\r\n0\r\n\r\n";

        $socket = new SocketConnection();
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $socket->connection->context->chunkedAt = [strlen($buffer) + 1, true];
        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));

        // a new head
        $socket = new SocketConnection();
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $socket->connection->context->chunkedAt = [5, true];
        HttpProtocol::input('POST / HTTP/1.1', $socket->connection);
        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));
    }

    public function testManySmallChunks(): void
    {
        $socket = new SocketConnection();
        $socket->connection->maxPackageSize = 2 * 1024 * 1024;
        $chunks = str_repeat("1\r\na\r\n", 160 * 1024);
        $buffer = self::CHUNKED . $chunks . "0\r\n\r\n";

        for ($read = 64 * 1024; $read < strlen($buffer); $read += 64 * 1024) {
            self::assertSame(0, HttpProtocol::input(substr($buffer, 0, $read), $socket->connection));
        }
        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));

        $request = HttpProtocol::decode($buffer, $socket->connection);
        self::assertInstanceOf(Request::class, $request);
        self::assertSame(str_repeat('a', 160 * 1024), $request->rawBody());
    }

    public function testOtherBodiesAreNotValidatedAsChunks(): void
    {
        $socket = new SocketConnection();
        $body = "{\n\t\"a\": \"\x01\r\"\n}";
        $buffer = "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

        self::assertSame(strlen($buffer), HttpProtocol::input($buffer, $socket->connection));
        self::assertSame('', $socket->read());
    }

    /**
     * A proxy that reads a bare LF or CR as a line end would see another head (request smuggling), rfc 9112 2.2
     */
    #[TestWith(["GET / HTTP/1.1\n\r\nHost: localhost\r\n\r\n"], 'request line')]
    #[TestWith(["GET / HTTP/1.1\r\nHost: localhost\r\nX-A: a\n\r\n\r\n"], 'header value')]
    #[TestWith(["GET / HTTP/1.1\r\nHost: localhost\n\r\n\r\n"], 'host')]
    #[TestWith(["GET / HTTP/1.1\r\nHost: localhost\r\nX-A: a\rb\r\n\r\n"], 'bare cr')]
    public function testBareLineEnds(string $buffer): void
    {
        $socket = new SocketConnection();

        self::assertSame(0, HttpProtocol::input($buffer, $socket->connection));
        self::assertStringStartsWith('HTTP/1.1 400 ', $socket->read());
    }

    /**
     * Workerman passes requests it has seen before from its cache, without decoding them again
     */
    public function testChunkedRequestFromTheCache(): void
    {
        // the cache is shared by all connections of the process
        $path = '/' . uniqid('cached-');
        $chunked = "POST $path HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n";
        $bodies = [];
        $onMessage = static function (TcpConnection $connection, Request $request) use (&$bodies): void {
            $bodies[] = $request->rawBody();
        };

        $first = new SocketConnection();
        $first->connection->onMessage = $onMessage;
        $first->write($chunked);

        // the same request in two reads, from the cache, then another request on the connection
        $second = new SocketConnection();
        $second->connection->onMessage = $onMessage;
        $headEnd = (int) strpos($chunked, "\r\n\r\n") + 4;
        $second->write(substr($chunked, 0, $headEnd));
        $second->write(substr($chunked, $headEnd));
        $second->write("POST $path HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\nhello");

        self::assertSame(['abc', 'abc', 'hello'], $bodies);
        self::assertSame('', $second->read());
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
