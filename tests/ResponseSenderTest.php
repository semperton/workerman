<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Semperton\Workerman\HttpProtocol;
use Semperton\Workerman\PushStream;
use Semperton\Workerman\ResponseSender;
use Semperton\Workerman\Tests\Fixtures\ReportedSizeStream;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use UnexpectedValueException;
use Workerman\Connection\TcpConnection;

use function explode;
use function hexdec;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;

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
        self::assertSame('', $body);
    }

    public function testConnectionClose(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'close');
        [$head] = $this->split($socket->read());

        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertTrue($socket->ended());
    }

    public function testCloseImmediately(): void
    {
        $socket = new SocketConnection();
        $this->sender()->send($socket->connection, new Response(200, [], 'hello'), 'GET', '1.1', 'close');

        // nothing to wait for, lingering would keep the connection
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
    }

    public function testCloseGracefully(): void
    {
        $socket = new SocketConnection();
        $socket->connection->protocol = HttpProtocol::class;
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
        $socket = new SocketConnection();
        $response = new Response(200, [], new ReportedSizeStream(Stream::create($content), $size + 10));
        $this->sender()->send($socket->connection, $response, 'GET', '1.1', '');
        [$head, $body] = $this->split($socket->read());

        // the client notices the incomplete body, as the connection is closed
        self::assertStringContainsString('Content-Length: ' . ($size + 10), $head);
        self::assertSame($content, $body);
        self::assertTrue($socket->ended());
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
        $response = new Response(200, [], new ReportedSizeStream(Stream::create($content)));
        [$head, $body] = $this->send($response);

        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertStringNotContainsString('Content-Length', $head);
        self::assertSame($content, $this->decodeChunked($body));
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

        [$head, $body] = $this->split($socket->read());

        self::assertStringContainsString("\r\nContent-Length: " . strlen($content), $head);
        self::assertSame($content, $body);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testClientGone(): void
    {
        $body = Stream::create(str_repeat('0123456789abcdef', 256 * 1024)); // 4 MB, streamed

        $socket = new SocketConnection();
        $socket->close();
        $this->sender()->send($socket->connection, new Response(200, [], $body), 'GET', '1.1', '');

        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
        self::assertNull($socket->connection->onBufferDrain);
        // closed, not left to the garbage collector
        self::assertFalse($body->isReadable());
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

        $stream->end();
        self::assertSame("0\r\n\r\n", $socket->read());
        self::assertTrue($closed);
        self::assertFalse($stream->push('data: c'));
        // ready for the next request
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
        $received = '';
        $socket->connection->onMessage = static function (TcpConnection $connection, string $data) use (&$received): void {
            $received = $data;
        };
        $socket->write('next');
        self::assertSame('next', $received);
    }

    public function testPushEndedBeforeSend(): void
    {
        $stream = new PushStream();
        $stream->push('hello');
        $stream->end();
        [, $body] = $this->send(new Response(200, [], $stream));

        self::assertSame("5\r\nhello\r\n0\r\n\r\n", $body);
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
        return new ResponseSender(new NullLogger());
    }

    /**
     * @return array{string, string}
     */
    private function split(string $data): array
    {
        $pos = (int) strpos($data, "\r\n\r\n");

        return [substr($data, 0, $pos), substr($data, $pos + 4)];
    }

    private function decodeChunked(string $data): string
    {
        $body = '';

        while (true) {
            [$size] = explode("\r\n", $data, 2);
            $length = (int) hexdec($size);
            if ($length === 0) {
                return $body;
            }
            $start = strlen($size) + 2;
            $body .= substr($data, $start, $length);
            $data = substr($data, $start + $length + 2);
        }
    }
}
