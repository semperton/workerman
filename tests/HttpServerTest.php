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
use Semperton\Workerman\Tests\Fixtures\AddedWorker;
use Semperton\Workerman\Tests\Fixtures\ArrayLogger;
use Semperton\Workerman\Tests\Fixtures\LifecycleHandler;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

use function pcntl_signal;
use function pcntl_signal_dispatch;

use const SIG_DFL;
use const SIGQUIT;

final class HttpServerTest extends TestCase
{
    private const ADDRESS = 'http://127.0.0.1:8080';

    public function testRequest(): void
    {
        $socket = new SocketConnection();
        self::handle($this->worker(), $socket->connection, new Request("GET /users/1 HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        $data = $socket->read();
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $data);
        self::assertStringEndsWith("\r\n\r\nGET /users/1", $data);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testInvalidHost(): void
    {
        $socket = new SocketConnection();
        self::handle($this->worker(), $socket->connection, new Request("GET / HTTP/1.1\r\nHost: a#\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());
        self::assertTrue($socket->ended());
    }

    public function testInvalidHeaderValue(): void
    {
        $socket = new SocketConnection();
        self::handle($this->worker(), $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\nX-Id: a\x00b\r\n\r\n"));

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
        self::handle($this->worker(), $socket->connection, $request);

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
    }

    public function testInterimResponse(): void
    {
        $socket = new SocketConnection();
        $worker = $this->worker(static fn(): ResponseInterface => new Response(101));
        self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'https' => ['https://0.0.0.0:8443'];
        yield 'tcp' => ['tcp://0.0.0.0:8080'];
        yield 'empty' => ['http://'];
        yield 'missing port' => ['http://0.0.0.0'];
        yield 'missing host' => ['http://:8080'];
        yield 'path' => ['http://0.0.0.0:8080/api'];
        yield 'credentials' => ['http://user@0.0.0.0:8080'];
    }

    #[DataProvider('invalidAddresses')]
    public function testInvalidAddress(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->server()->listen($address);
    }

    public function testWorker(): void
    {
        $server = $this->server();
        $worker = AddedWorker::of(static fn() => $server->listen('http://[::1]:8080', workers: 2, user: 'www-data', group: 'www'));

        self::assertSame('tcp://[::1]:8080', $worker->getSocketName());
        self::assertSame(2, $worker->count);
        self::assertSame('www-data', $worker->user);
        self::assertSame('www', $worker->group);
    }

    /**
     * @return iterable<string, array{Closure(HttpServer): mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'workers' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, workers: 0)];
        yield 'max body size' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, maxBodySize: -1)];
        yield 'max requests' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, maxRequests: -1)];
        yield 'idle timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, idleTimeout: -1)];
        yield 'header timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, headerTimeout: -1)];
        yield 'stop timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, stopTimeout: 0)];
        yield 'negative stop timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, stopTimeout: -1)];
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
        $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS));

        $socket = new SocketConnection();
        self::handle($worker, $socket->connection, new Request("GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());

        // a client error, the handler wasn't called
        $socket = new SocketConnection();
        self::handle($worker, $socket->connection, new Request("GET /b HTTP/1.1\r\nHost: a#\r\n\r\n"));
        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());

        // the state may have changed before the error
        self::assertSame(['handled /a'], $handler->calls());
    }

    public function testHandlerError(): void
    {
        $logger = new ArrayLogger();

        $socket = new SocketConnection();
        $worker = $this->worker(static fn() => throw new RuntimeException('Failed'), $logger);
        self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

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
        $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS, maxBodySize: 1024, maxRequests: 2));
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
            self::assertSame(1024, $socket->connection->context?->maxBodySize);
            self::assertSame($defaultMaxPackageSize, TcpConnection::$defaultMaxPackageSize);

            self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            self::assertStringNotContainsString('Connection: close', $socket->read());

            self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
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
        $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS));

        $socket = new SocketConnection();
        self::handle($worker, $socket->connection, new Request("GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        self::handle($worker, $socket->connection, new Request("GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n"));

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
            $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS));

            $socket = new SocketConnection();
            self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

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
     * Like Workerman, for every request
     */
    private static function handle(Worker $worker, TcpConnection $connection, Request $request): void
    {
        self::assertNotNull($worker->onMessage);
        ($worker->onMessage)($connection, $request);
    }

    /**
     * @param ?callable(ServerRequestInterface): ResponseInterface $handle
     */
    private function worker(?callable $handle = null, ?LoggerInterface $logger = null): Worker
    {
        $server = $this->server($handle, $logger);

        return AddedWorker::of(static fn() => $server->listen(self::ADDRESS));
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
