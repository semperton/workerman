<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Internal;

use ArrayObject;
use Closure;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Semperton\Workerman\Internal\ResponseSender;
use Semperton\Workerman\PushStream;
use Semperton\Workerman\Tests\Fixtures\ArrayLogger;
use Semperton\Workerman\Tests\Fixtures\FailingStream;
use Semperton\Workerman\Tests\Fixtures\ReportedSizeStream;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Semperton\Workerman\Tests\Fixtures\StreamDecorator;
use Semperton\Workerman\Tests\Fixtures\UnvalidatedResponse;
use UnexpectedValueException;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

use function explode;
use function hexdec;
use function preg_match;
use function str_repeat;
use function strlen;
use function strpos;
use function strtotime;
use function substr;
use function substr_count;
use function time;
use function uniqid;

final class ResponseSenderTest extends TestCase
{
    public function testResponse(): void
    {
        $response = new Response(201, ['Content-Type' => 'text/plain', 'Set-Cookie' => ['a=1', 'b=2']], 'hello');
        [$head, $body] = $this->send($response);

        self::assertStringStartsWith("HTTP/1.1 201 Created\r\n", $head);
        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertStringContainsString("\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2", $head);
        self::assertStringContainsString("\r\nDate: ", $head);
        self::assertStringNotContainsString('Connection:', $head);
        self::assertSame('hello', $body);
    }

    public function testHead(): void
    {
        [$head, $body] = $this->send(new Response(200, [], 'hello'), 'HEAD');

        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertSame('', $body);
    }

    public function testNoContent(): void
    {
        [$head, $body] = $this->send(new Response(204, ['Content-Length' => '5'], 'hello'));

        self::assertStringNotContainsString('Content-Length', $head);
        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertSame('', $body);
    }

    public function testPartialContent(): void
    {
        [$head, $body] = $this->send(new Response(206, ['Content-Range' => 'bytes 0-4/10'], 'hello'));

        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertSame('hello', $body);
    }

    public function testDate(): void
    {
        [$head] = $this->send(new Response(200, ['Content-Type' => 'text/plain'], 'hello'));

        self::assertSame(1, substr_count($head, "\r\nDate: "));
        self::assertMatchesRegularExpression('/\r\nDate: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT(\r\n|$)/', $head);
        preg_match('/\r\nDate: ([^\r]+)/', $head, $matches);
        self::assertEqualsWithDelta(time(), strtotime($matches[1] ?? ''), 2);
    }

    public function testResetContent(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(205, [], 'hello'), 'POST', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        self::assertStringStartsWith("HTTP/1.1 205 Reset Content\r\n", $head);
        self::assertStringContainsString("\r\nContent-Length: 0", $head);
        self::assertSame('', $body);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testNotModified(): void
    {
        $socket = new SocketConnection();
        // without a body, like the application would send it
        $this->sender()->send($socket->connection, new Response(304, ['Content-Length' => '5']), 'GET', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        // the length a 200 would have
        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertSame('', $body);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testFramingHeaders(): void
    {
        $date = 'Sun, 11 Oct 2026 12:00:00 GMT';
        $response = new Response(200, ['Transfer-Encoding' => 'chunked', 'Content-Length' => '99', 'Date' => $date], 'hello');
        [$head, $body] = $this->send($response);

        // the server frames the body, the application's headers would contradict it
        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertStringNotContainsString('99', $head);
        self::assertSame(1, substr_count($head, "\r\nDate: "));
        self::assertStringContainsString("\r\nDate: $date", $head);
        self::assertSame('hello', $body);
    }

    public function testInvalidReasonPhrase(): void
    {
        [$head] = $this->send((new Response())->withStatus(200, "OK\r\nX-Injected: 1"));

        self::assertStringStartsWith("HTTP/1.1 200 \r\n", $head);
        self::assertStringNotContainsString('X-Injected', $head);
    }

    /**
     * @return iterable<string, array{Closure(): ResponseInterface}>
     */
    public static function invalidHeaders(): iterable
    {
        // nyholm validates a value with '$', which matches before a final line feed
        yield 'value ending in lf' => [static fn() => new Response(302, ['Location' => "/next\n"])];
        yield 'value with crlf' => [static fn() => new UnvalidatedResponse(['X-A' => ["a\r\nSet-Cookie: injected=1"]])];
        yield 'value with nul' => [static fn() => new UnvalidatedResponse(['X-A' => ["a\0b"]])];
        yield 'name with space' => [static fn() => new UnvalidatedResponse(['X A' => ['a']])];
        yield 'name with colon' => [static fn() => new UnvalidatedResponse(['X-A: b' => ['a']])];
    }

    /**
     * Not every PSR-7 implementation validates the headers, a line break would add headers (response splitting)
     *
     * @param Closure(): ResponseInterface $response
     */
    #[DataProvider('invalidHeaders')]
    public function testInvalidHeader(Closure $response): void
    {
        $socket = new SocketConnection();

        try {
            $this->sender()->send($socket->connection, $response(), 'GET', '1.1', '');
            self::fail('Expected an exception');
        } catch (UnexpectedValueException) {
            self::assertSame('', $socket->read(), 'nothing sent');
        }
    }

    #[DataProvider('sizes')]
    public function testRewind(int $size): void
    {
        $body = Stream::create(str_repeat('a', $size));
        // e.g. read by a middleware
        $body->getContents();

        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], $body), 'GET', '1.1', '');
        [, $data] = $this->split($socket->read());

        self::assertSame($size, strlen($data));
    }

    public function testStreamError(): void
    {
        $body = new FailingStream(Stream::create(str_repeat('0123456789abcdef', 256 * 1024))); // 4 MB, streamed

        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        (new ResponseSender($logger))->send($socket->connection, new Response(200, [], $body), 'GET', '1.1', '');
        $socket->read();

        // the head is sent, closing is the only way to signal the error
        self::assertNotSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
        self::assertSame(['Unable to stream the response body'], $logger->messages);
    }

    public function testResponseConnectionClose(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, ['Connection' => 'close'], 'hello'), 'GET', '1.1', 'keep-alive');
        [$head] = $this->split($socket->read());

        self::assertSame(1, substr_count($head, 'Connection:'));
        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
    }

    public function testConnectionClose(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'close');

        // nothing to wait for, lingering would keep the connection
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
        [$head] = $this->split($socket->read());
        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertTrue($socket->ended());
    }

    public function testCloseGracefully(): void
    {
        $socket = new SocketConnection();
        $socket->connection->onMessage = static function (): void {};
        // a pipelined request, which won't be answered
        $socket->write("GET /next HTTP/1.1\r\n");

        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'close');

        // the client may still be sending, a close would reset the connection and could destroy the response
        self::assertSame(TcpConnection::STATUS_ENDING, $socket->connection->getStatus());
        self::assertStringEndsWith("\r\n\r\nhello", $socket->read());
        self::assertTrue($socket->ended());
    }

    public function testConnectionTokens(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'Upgrade, Close');
        $socket->read();
        self::assertTrue($socket->ended());

        // not the close option
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'x-closed');
        $socket->read();
        self::assertFalse($socket->ended());
    }

    public function testInterimResponse(): void
    {
        $socket = new SocketConnection();

        try {
            $this->sender()->send($socket->connection, new Response(100), 'GET', '1.1', '');
            self::fail('Expected an exception');
        } catch (UnexpectedValueException) {
            self::assertSame('', $socket->read(), 'nothing sent');
        }
    }

    public function testInvalidContentLength(): void
    {
        $body = new ReportedSizeStream(Stream::create('hello'));
        [$head, $body] = $this->send(new Response(200, ['Content-Length' => '5x'], $body));

        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertStringNotContainsString('Content-Length', $head);
        self::assertSame('hello', $this->decodeChunked($body));
    }

    public function testUnreadableBody(): void
    {
        // e.g. a socket, read() returns '' while no data is available, PSR-7 allows it
        $body = new class (Stream::create('first part;')) extends StreamDecorator {
            public function getSize(): ?int
            {
                return null;
            }

            public function eof(): bool
            {
                return false;
            }
        };

        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        (new ResponseSender($logger))->send($socket->connection, new Response(200, [], $body), 'GET', '1.1', '');
        [$head, $data] = $this->split($socket->read());

        // without the last chunk, the client notices the incomplete body, as the connection is closed
        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertSame("b\r\nfirst part;\r\n", $data);
        self::assertTrue($socket->ended());
        self::assertSame(['The response body could not be read to its end'], $logger->messages);
    }

    public function testContentLengthOfUnknownSize(): void
    {
        $body = new ReportedSizeStream(Stream::create('hello'));
        [$head, $body] = $this->send(new Response(200, ['Content-Length' => '5'], $body));

        self::assertStringContainsString("\r\nContent-Length: 5", $head);
        self::assertSame('hello', $body);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        yield 'sent at once' => [1024];
        yield 'streamed' => [3 * 1024 * 1024];
    }

    #[DataProvider('sizes')]
    public function testShorterBody(int $size): void
    {
        $content = str_repeat('a', $size);
        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        $response = new Response(200, [], new ReportedSizeStream(Stream::create($content), $size + 10));
        (new ResponseSender($logger))->send($socket->connection, $response, 'GET', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        // the client notices the incomplete body, as the connection is closed
        self::assertStringContainsString('Content-Length: ' . ($size + 10), $head);
        self::assertSame($content, $body);
        self::assertTrue($socket->ended());
        self::assertCount(1, $logger->messages);
    }

    #[DataProvider('sizes')]
    public function testLongerBody(int $size): void
    {
        $content = str_repeat('a', $size);
        $socket = new SocketConnection();
        $response = new Response(200, [], new ReportedSizeStream(Stream::create($content . 'too long'), $size));
        $this->sender()->send($socket->connection, $response, 'GET', '1.1', '');
        [, $body] = $this->split($socket->read());

        self::assertSame($content, $body);
        self::assertFalse($socket->ended());
    }

    public function testHttp10KeepAlive(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.0', 'keep-alive');
        [$head] = $this->split($socket->read());

        self::assertStringContainsString("\r\nConnection: keep-alive", $head);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testChunked(): void
    {
        $content = str_repeat('0123456789', 10_000);
        $socket = new SocketConnection();
        $response = new Response(200, [], new ReportedSizeStream(Stream::create($content)));
        $this->sender()->send($socket->connection, $response, 'GET', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertStringNotContainsString('Content-Length', $head);
        self::assertSame($content, $this->decodeChunked($body));
        // the last chunk ends the body, the connection stays open
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testHttp10UnknownSize(): void
    {
        $socket = new SocketConnection();
        $response = new Response(200, [], new ReportedSizeStream(Stream::create('hello')));
        $this->sender()->send($socket->connection, $response, 'GET', '1.0', 'keep-alive');
        [$head, $body] = $this->split($socket->read());

        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertSame('hello', $body);
        self::assertTrue($socket->ended());
    }

    public function testBackpressure(): void
    {
        $content = str_repeat('0123456789abcdef', 256 * 1024); // 4 MB, streamed

        $socket = new SocketConnection();
        $socket->connection->maxSendBufferSize = 64 * 1024;
        $this->sender()->send($socket->connection, new Response(200, [], $content), 'GET', '1.1', '');

        // waits for the client
        self::assertNotNull($socket->connection->onBufferDrain);

        // a pipelined request waits for the response
        $received = new ArrayObject();
        $socket->connection->onMessage = static function (TcpConnection $connection, Request $request) use ($received): void {
            $received->append($request->path());
        };
        // unique, workerman passes cached requests even while reading is paused, the event loop doesn't read then
        $path = '/' . uniqid('next-');
        $socket->write("GET $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        self::assertSame([], $received->getArrayCopy());

        [$head, $body] = $this->split($socket->read());

        self::assertStringContainsString("\r\nContent-Length: " . strlen($content), $head);
        self::assertSame($content, $body);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
        self::assertSame([$path], $received->getArrayCopy());
    }

    public function testClientGone(): void
    {
        $body = Stream::create(str_repeat('0123456789abcdef', 256 * 1024)); // 4 MB, streamed

        $socket = new SocketConnection();
        // the response waits for the client to read
        $this->sender()->send($socket->connection, new Response(200, [], $body), 'GET', '1.1', '');
        $socket->close();
        // the event loop notices
        $socket->write('');

        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
        self::assertNull($socket->connection->onBufferDrain);
        // closed, not left to the garbage collector
        self::assertFalse($body->isReadable());
    }

    public function testStreamKeepsOnClose(): void
    {
        $onClose = static function (): void {};

        $socket = new SocketConnection();
        $socket->connection->onClose = $onClose;
        $this->sender()->send($socket->connection, new Response(200, [], str_repeat('a', 3 * 1024 * 1024)), 'GET', '1.1', '');
        $socket->read();

        // restored, e.g. for the next response on the connection
        self::assertSame($onClose, $socket->connection->onClose);
    }

    public function testPush(): void
    {
        $closed = false;
        $stream = new PushStream();
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        // buffered until the response is sent
        self::assertTrue($stream->push('data: a'));

        $socket = new SocketConnection();
        $response = new Response(200, ['Content-Type' => 'text/event-stream', 'Content-Length' => '5'], $stream);
        $this->sender()->send($socket->connection, $response, 'GET', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        // the length of a push is unknown
        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertStringNotContainsString('Content-Length', $head);
        self::assertSame("7\r\ndata: a\r\n", $body);

        self::assertTrue($stream->push('data: b'));
        self::assertTrue($stream->push(''));
        self::assertSame("7\r\ndata: b\r\n", $socket->read());
        self::assertFalse($closed);

        // a pipelined request waits for the end of the body
        $received = new ArrayObject();
        $socket->connection->onMessage = static function (TcpConnection $connection, Request $request) use ($received): void {
            $received->append($request->path());
        };
        // unique, workerman passes cached requests even while reading is paused, the event loop doesn't read then
        $path = '/' . uniqid('next-');
        $socket->write("GET $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        self::assertSame([], $received->getArrayCopy());

        $stream->end();
        self::assertSame("0\r\n\r\n", $socket->read());
        self::assertTrue($closed);
        self::assertFalse($stream->push('data: c'));
        // ready for the next request
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
        self::assertSame([$path], $received->getArrayCopy());
    }

    public function testPushSentTwice(): void
    {
        $stream = new PushStream();
        $first = new SocketConnection();
        $this->sender()->send($first->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        $first->read();

        $second = new SocketConnection();
        try {
            $this->sender()->send($second->connection, new Response(200, [], $stream), 'GET', '1.1', '');
            self::fail('Expected an exception');
        } catch (UnexpectedValueException) {
            self::assertSame('', $second->read(), 'nothing sent');
        }

        // the first response continues
        self::assertTrue($stream->push('a'));
        self::assertSame("1\r\na\r\n", $first->read());
    }

    public function testPushEndedBeforeSend(): void
    {
        $stream = new PushStream();
        $stream->push('hello');
        $stream->end();
        [, $body] = $this->send(new Response(200, [], $stream));

        self::assertSame("5\r\nhello\r\n0\r\n\r\n", $body);
    }

    public function testPushEndedBeforeSendListenerError(): void
    {
        $stream = new PushStream();
        $stream->onClose(static fn() => throw new RuntimeException('Failed'));
        $stream->push('hello');
        $stream->end();

        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        // the head is sent, a thrown exception would make the server send an error response after it
        (new ResponseSender($logger))->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        [, $body] = $this->split($socket->read());

        self::assertSame("5\r\nhello\r\n0\r\n\r\n", $body);
        self::assertSame(['Failed'], $logger->messages);
    }

    public function testPushHeadListenerError(): void
    {
        $stream = new PushStream();
        $stream->onClose(static fn() => throw new RuntimeException('Failed'));

        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        // like on a GET, where the error is logged once the stream is closed
        (new ResponseSender($logger))->send($socket->connection, new Response(200, [], $stream), 'HEAD', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        self::assertStringStartsWith('HTTP/1.1 200 OK', $head);
        self::assertSame('', $body);
        self::assertSame(['Failed'], $logger->messages);
    }

    public function testPushHead(): void
    {
        $closed = false;
        $stream = new PushStream();
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        [, $body] = $this->send(new Response(200, [], $stream), 'HEAD');

        self::assertSame('', $body);
        self::assertTrue($closed);
        self::assertFalse($stream->push('data: a'));
    }

    public function testPushHttp10(): void
    {
        $stream = new PushStream();
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.0', 'keep-alive');
        $stream->push('hello');
        $stream->end();
        [$head, $body] = $this->split($socket->read());

        // the end of the body is the end of the connection
        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertSame('hello', $body);
        self::assertTrue($socket->ended());
    }

    public function testPushBackpressure(): void
    {
        $stream = new PushStream();
        $socket = new SocketConnection();
        $socket->connection->maxSendBufferSize = 1;
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');

        // more than the socket takes, the rest is buffered, then pushes are refused
        $data = str_repeat('0123456789abcdef', 64 * 1024);
        $pushed = 0;
        while ($stream->push($data)) {
            ++$pushed;
        }

        self::assertGreaterThan(0, $pushed);
        [, $body] = $this->split($socket->read());
        // the body hasn't ended yet
        self::assertSame(str_repeat($data, $pushed), $this->decodeChunked($body . "0\r\n\r\n"));
        // the client has read, pushes are taken again
        self::assertTrue($stream->push('a'));
    }

    public function testPushClientGone(): void
    {
        $closed = false;
        $stream = new PushStream();
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        $socket->read();
        $socket->close();
        // the event loop notices
        $socket->write('');

        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
        self::assertTrue($closed);
        self::assertFalse($stream->push('data: a'));
    }

    public function testPushCloseListenerError(): void
    {
        $closed = false;
        $stream = new PushStream();
        $stream->onClose(static fn() => throw new RuntimeException('Failed'));
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $connectionClosed = false;
        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        // e.g. set by the application on the worker
        $socket->connection->onClose = static function () use (&$connectionClosed): void {
            $connectionClosed = true;
        };
        (new ResponseSender($logger))->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        $socket->read();
        $socket->close();
        // workerman would stop the worker if it was thrown
        $socket->write('');

        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
        self::assertTrue($closed, 'every listener is called');
        self::assertTrue($connectionClosed, 'the callback of the application is kept');
        self::assertSame(['Failed'], $logger->messages);
    }

    public function testPushKeepsOnClose(): void
    {
        $stream = new PushStream();
        $onClose = static function (): void {};

        $socket = new SocketConnection();
        $socket->connection->onClose = $onClose;
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        $stream->end();

        self::assertSame($onClose, $socket->connection->onClose);
    }

    public function testPushClosedBeforeSend(): void
    {
        $stream = new PushStream();
        $stream->push('data: a');
        $stream->close();

        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');

        // aborted, the client must not take the body as complete
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $socket->read());
        self::assertNotSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testPushClosed(): void
    {
        $stream = new PushStream();
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], $stream), 'GET', '1.1', '');
        $stream->push('hello');
        // an aborted body closes the connection, so the client notices
        $stream->close();
        [, $body] = $this->split($socket->read());

        self::assertSame("5\r\nhello\r\n", $body);
        self::assertTrue($socket->ended());
    }

    /**
     * @return array{string, string}
     */
    private function send(ResponseInterface $response, string $method = 'GET'): array
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, $response, $method, '1.1', 'keep-alive');

        return $this->split($socket->read());
    }

    private function sender(): ResponseSender
    {
        return new ResponseSender();
    }

    /**
     * @return array{string, string}
     */
    private function split(string $data): array
    {
        $pos = (int) strpos($data, "\r\n\r\n");

        return [substr($data, 0, $pos), substr($data, $pos + 4)];
    }

    /**
     * Fails for invalid framing, e.g. a missing last chunk, which would keep clients waiting
     */
    private function decodeChunked(string $data): string
    {
        $body = '';

        while (true) {
            [$size] = explode("\r\n", $data, 2);
            self::assertMatchesRegularExpression('/^[0-9a-f]+$/D', $size, 'a chunk size');
            $length = (int) hexdec($size);
            $start = strlen($size) + 2;
            if ($length === 0) {
                self::assertSame("\r\n", substr($data, $start), 'the last chunk ends the body');
                return $body;
            }
            self::assertSame("\r\n", substr($data, $start + $length, 2), 'the chunk data ends with a crlf');
            $body .= substr($data, $start, $length);
            $data = substr($data, $start + $length + 2);
        }
    }
}
