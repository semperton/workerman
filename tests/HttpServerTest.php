<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use Closure;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\Tests\Fixtures\ArrayLogger;
use Semperton\Workerman\Tests\Fixtures\LifecycleHandler;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
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
        self::assertTrue($socket->ended());
    }

    public function testInvalidHeaderValue(): void
    {
        $socket = new SocketConnection();
        $this->server()->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\nX-Id: a\x00b\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());
    }

    public function testConverterError(): void
    {
        // a bug, not a client error
        $request = new class ("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n") extends Request {
            public function rawBody(): string
            {
                throw new RuntimeException('Failed');
            }
        };

        $socket = new SocketConnection();
        $this->server()->handle($socket->connection, $request);

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
    }

    public function testInterimResponse(): void
    {
        $socket = new SocketConnection();
        $server = $this->server(static fn(): ResponseInterface => new Response(101));
        $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
    }

    public function testInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->server()->listen('https://0.0.0.0:8443');
    }

    /**
     * @return iterable<string, array{Closure(HttpServer): mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'workers' => [static fn(HttpServer $server) => $server->listen(workers: 0)];
        yield 'max body size' => [static fn(HttpServer $server) => $server->listen(maxBodySize: -1)];
        yield 'max requests' => [static fn(HttpServer $server) => $server->listen(maxRequests: -1)];
        yield 'idle timeout' => [static fn(HttpServer $server) => $server->listen(idleTimeout: -1)];
        yield 'header timeout' => [static fn(HttpServer $server) => $server->listen(headerTimeout: -1)];
        yield 'stop timeout' => [static fn(HttpServer $server) => $server->listen(stopTimeout: 0)];
        yield 'negative stop timeout' => [static fn(HttpServer $server) => $server->listen(stopTimeout: -1)];
    }

    /**
     * @param Closure(HttpServer): mixed $listen
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptions(Closure $listen): void
    {
        $this->expectException(InvalidArgumentException::class);

        $listen($this->server());
    }

    public function testLifecycleAfterError(): void
    {
        $handler = new LifecycleHandler(handleError: new RuntimeException('Failed'));
        $server = new HttpServer(static fn() => $handler);

        $socket = new SocketConnection();
        $server->handle($socket->connection, new Request("GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());

        // a client error, the handler wasn't called
        $socket = new SocketConnection();
        $server->handle($socket->connection, new Request("GET /b HTTP/1.1\r\nHost: a#\r\n\r\n"));
        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());

        // the state may have changed before the error
        self::assertSame(['handled /a'], $handler->calls());
    }

    public function testHandlerError(): void
    {
        $logger = new ArrayLogger();

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
            // initializes the timers
            $socket = new SocketConnection();
            self::assertNotNull($worker->onWorkerStart);
            ($worker->onWorkerStart)($worker);
            self::assertNotNull($worker->onConnect);
            ($worker->onConnect)($socket->connection);
            // the body limit plus the head, only for the connections of the worker
            self::assertSame(1024 + 16 * 1024, $socket->connection->maxPackageSize);
            self::assertSame($defaultMaxPackageSize, TcpConnection::$defaultMaxPackageSize);

            $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            self::assertStringNotContainsString('Connection: close', $socket->read());

            $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            self::assertStringContainsString("\r\nConnection: close", $socket->read());
            self::assertTrue($socket->ended());

            pcntl_signal_dispatch();
            self::assertTrue($stopped);
        } finally {
            pcntl_signal(SIGQUIT, SIG_DFL);
        }
    }

    public function testLifecycle(): void
    {
        $handler = new LifecycleHandler();
        $server = new HttpServer(static fn() => $handler);
        $worker = $server->listen();

        $socket = new SocketConnection();
        $server->handle($socket->connection, new Request("GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        $server->handle($socket->connection, new Request("GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        // workerStopping() is called when the process exits, see ServerTest
        self::assertNotNull($worker->onWorkerStop);
        ($worker->onWorkerStop)($worker);
        self::assertSame(['handled /a', 'handled /b'], $handler->calls());
    }

    public function testLifecycleError(): void
    {
        $stopped = false;
        pcntl_signal(SIGQUIT, static function () use (&$stopped): void {
            $stopped = true;
        });

        try {
            $logger = new ArrayLogger();
            $handler = new LifecycleHandler(new RuntimeException('Reset failed'));
            $server = new HttpServer(static fn() => $handler, logger: $logger);

            $socket = new SocketConnection();
            $server->handle($socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

            // the response was sent before, the worker restarts
            self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $socket->read());
            self::assertSame(['Reset failed'], $logger->messages);
            pcntl_signal_dispatch();
            self::assertTrue($stopped);
        } finally {
            pcntl_signal(SIGQUIT, SIG_DFL);
        }
    }

    /**
     * @param ?callable(ServerRequestInterface): ResponseInterface $handle
     */
    private function server(?callable $handle = null, ?LoggerInterface $logger = null): HttpServer
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

        return new HttpServer(static fn() => $handler, logger: $logger ?? new NullLogger());
    }
}
