<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use ArrayObject;
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
use ReflectionMethod;
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

use const INF;
use const NAN;
use const PHP_INT_MAX;
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
        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        self::handle($this->worker(logger: $logger), $socket->connection, new Request("GET / HTTP/1.1\r\nHost: a#\r\n\r\n"));

        self::assertStringStartsWith("HTTP/1.1 400 Bad Request\r\n", $socket->read());
        self::assertTrue($socket->ended());
        self::assertSame(['Invalid host < a# >'], $logger->messages);
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

        $logger = new ArrayLogger();
        $socket = new SocketConnection();
        self::handle($this->worker(logger: $logger), $socket->connection, $request);

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $socket->read());
        self::assertTrue($socket->ended());
        self::assertSame(['Failed'], $logger->messages);
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
        // a stop would never end
        yield 'nan stop timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, stopTimeout: NAN)];
        yield 'infinite stop timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, stopTimeout: INF)];
        yield 'nan idle timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, idleTimeout: NAN)];
        yield 'infinite idle timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, idleTimeout: INF)];
        yield 'nan header timeout' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, headerTimeout: NAN)];
        // the package size, body and head, would exceed an int
        yield 'max body size too large' => [static fn(HttpServer $server) => $server->listen(self::ADDRESS, maxBodySize: PHP_INT_MAX)];
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

    public function testZeroLimits(): void
    {
        $server = $this->server();
        $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS, maxBodySize: 0, maxRequests: 0, idleTimeout: 0, headerTimeout: 0));

        // e.g. a server that accepts no bodies
        $socket = new SocketConnection();
        self::assertNotNull($worker->onConnect);
        ($worker->onConnect)($socket->connection);
        self::assertSame(16 * 1024, $socket->connection->maxPackageSize);
        self::assertSame(0, $socket->connection->context?->maxBodySize);
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
        self::assertTrue($socket->ended());
        self::assertSame(['Failed'], $logger->messages);
    }

    public function testHandlerCreatedAtStart(): void
    {
        $created = new ArrayObject();
        $server = new HttpServer(static function () use ($created): RequestHandlerInterface {
            $created->append(true);
            return new LifecycleHandler();
        });
        $worker = $this->startedWorker($server);

        // errors of the factory show up when the worker starts
        self::assertCount(1, $created);

        $socket = new SocketConnection();
        self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        self::handle($worker, $socket->connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        self::assertCount(1, $created);
    }

    public function testOnlyOwnConnections(): void
    {
        $server = $this->server();
        $worker = $this->startedWorker($server);
        $own = new SocketConnection();
        $own->connection->worker = $worker;
        // e.g. a database client of the application
        $other = new SocketConnection();

        // the worker stops, its idle connections are closed
        self::assertNotNull($worker->onWorkerStop);
        ($worker->onWorkerStop)($worker);
        self::checkConnections($server, $worker);

        self::assertTrue($own->ended());
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $other->connection->getStatus());
    }

    public function testWorkerStoppingOnce(): void
    {
        $logger = new ArrayLogger();
        $handler = new LifecycleHandler(workerStoppingError: new RuntimeException('Close failed'));
        $server = new HttpServer(static fn() => $handler, logger: $logger);
        $worker = $this->startedWorker($server);

        self::assertNotNull($worker->onWorkerStop);
        ($worker->onWorkerStop)($worker);
        // no connections of the worker, called once its last request is handled, and again when the process exits
        self::checkConnections($server, $worker);
        self::checkConnections($server, $worker);

        self::assertSame(['stopping'], $handler->calls());
        self::assertSame(['Close failed'], $logger->messages);
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
            $handler = new LifecycleHandler(requestHandledError: new RuntimeException('Reset failed'));
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
     * Like Workerman starts it, the timer of the connection checks is registered, the loop doesn't run
     */
    private function startedWorker(HttpServer $server): Worker
    {
        $worker = AddedWorker::of(static fn() => $server->listen(self::ADDRESS));
        // initializes the timers
        new SocketConnection();
        self::assertNotNull($worker->onWorkerStart);
        ($worker->onWorkerStart)($worker);

        return $worker;
    }

    /**
     * Like the timer of the worker, about once a second
     */
    private static function checkConnections(HttpServer $server, Worker $worker): void
    {
        (new ReflectionMethod($server, 'checkConnections'))->invoke($server, $worker);
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
     * @param ?Closure(ServerRequestInterface): ResponseInterface $handle
     */
    private function worker(?Closure $handle = null, ?LoggerInterface $logger = null): Worker
    {
        $server = $this->server($handle, $logger);

        return AddedWorker::of(static fn() => $server->listen(self::ADDRESS));
    }

    /**
     * @param ?Closure(ServerRequestInterface): ResponseInterface $handle
     */
    private function server(?Closure $handle = null, ?LoggerInterface $logger = null): HttpServer
    {
        // responds with the method and path of the request by default
        $handle ??= static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            body: $request->getMethod() . ' ' . $request->getUri()->getPath(),
        );

        $handler = new class ($handle) implements RequestHandlerInterface {
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
