<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Semperton\Workerman\ResponseSender;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Semperton\Workerman\Tests\Fixtures\UnknownSizeStream;
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
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
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
        $response = new Response(200, [], new UnknownSizeStream(Stream::create($content)));
        [$head, $body] = $this->send($response);

        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        self::assertStringNotContainsString('Content-Length', $head);
        self::assertSame($content, $this->decodeChunked($body));
    }

    public function testHttp10UnknownSize(): void
    {
        $socket = new SocketConnection();
        $response = new Response(200, [], new UnknownSizeStream(Stream::create('hello')));
        $this->sender()->send($socket->connection, $response, 'GET', '1.0', 'keep-alive');
        [$head, $body] = $this->split($socket->read());

        self::assertStringNotContainsString('Transfer-Encoding', $head);
        self::assertStringContainsString("\r\nConnection: close", $head);
        self::assertSame('hello', $body);
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
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
