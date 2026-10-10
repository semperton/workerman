<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\RequestConverter;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Stringable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

use function pcntl_signal;
use function pcntl_signal_dispatch;

use const SIG_DFL;
use const SIGQUIT;

final class HttpServerTest extends TestCase
{
    public function testRequest(): void
    {
        $socket = new SocketConnection();
        $this->server()->handle($socket->connection, new Request("GET /users/1 HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        $data = $socket->read();
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $data);
        self::assertStringEndsWith("\r\n\r\nGET /users/1", $data);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testInvalidHost(): void
    {
        $socket = new SocketConnection();
        $this->server()->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: a#\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
    }

    public function testHandlerError(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $socket = new SocketConnection();
        $server = $this->server(static fn() => throw new RuntimeException('Failed'), $logger);
        $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
        self::assertSame(['Failed'], $logger->messages);
    }

    public function testMaxRequests(): void
    {
        $stopped = false;
        pcntl_signal(SIGQUIT, static function () use (&$stopped): void {
            $stopped = true;
        });

        $server = $this->server();
        $worker = $server->listen(maxBodySize: 1024, maxRequests: 2);
        $defaultMaxPackageSize = TcpConnection::$defaultMaxPackageSize;

        try {
            self::assertNotNull($worker->onWorkerStart);
            ($worker->onWorkerStart)($worker);
            // the body limit plus the head
            self::assertSame(1024 + 16 * 1024, TcpConnection::$defaultMaxPackageSize);

            $socket = new SocketConnection();
            $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            self::assertStringNotContainsString('Connection: close', $socket->read());

            $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            self::assertStringContainsString("\r\nConnection: close", $socket->read());
            self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());

            pcntl_signal_dispatch();
            self::assertTrue($stopped);
        } finally {
            TcpConnection::$defaultMaxPackageSize = $defaultMaxPackageSize;
            pcntl_signal(SIGQUIT, SIG_DFL);
        }
    }

    /**
     * @param ?callable(ServerRequestInterface): ResponseInterface $handle
     */
    private function server(?callable $handle = null, ?AbstractLogger $logger = null): HttpServer
    {
        // responds with the method and path of the request by default
        $handle ??= static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            body: $request->getMethod() . ' ' . $request->getUri()->getPath(),
        );

        $handler = new class ($handle(...)) implements RequestHandlerInterface {
            /** @param Closure(ServerRequestInterface): ResponseInterface $handle */
            public function __construct(private readonly Closure $handle) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };

        $factory = new Psr17Factory();
        $converter = new RequestConverter($factory, $factory, $factory);

        return new HttpServer(static fn() => $handler, $converter, $logger ?? new NullLogger());
    }
}
