<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

use function posix_getpid;
use function posix_kill;
use function register_shutdown_function;
use function str_starts_with;
use function substr;

use const SIGQUIT;

/**
 * Serves a PSR-15 request handler with Workerman
 * Every worker process creates the handler once and handles all its requests with it
 */
final class HttpServer
{
    /** Workerman answers larger request heads with a 431 */
    private const MAX_HEAD_SIZE = 16 * 1024;

    private const BAD_REQUEST = "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    private const SERVER_ERROR = "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    private readonly ResponseSender $responseSender;
    private ?RequestHandlerInterface $handler = null;
    private ?WorkerLifecycle $lifecycle = null;
    private ?ConnectionTimeouts $timeouts = null;
    private int $maxRequests = 0;
    private int $requests = 0;
    /** the worker stops, every response closes its connection and idle connections are closed */
    private bool $draining = false;

    /**
     * @param Closure(): RequestHandlerInterface $handlerFactory called in every worker, after the fork,
     * so connections (e.g. to databases) are not shared between processes
     */
    public function __construct(
        private readonly Closure $handlerFactory,
        private readonly RequestConverter $requestConverter = new RequestConverter(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->responseSender = new ResponseSender($logger);
    }

    /**
     * Can be called more than once, every worker process runs one of the returned workers with its own options
     *
     * @param int $maxBodySize requests with a larger body get a 413, the body is kept in memory
     * @param int $maxRequests a worker restarts after this many requests, limits slow memory leaks, 0 = never
     * @param float $idleTimeout seconds a connection may receive and send nothing, e.g. between keep-alive requests, 0 = never
     * @param float $headerTimeout seconds a client may take to send a request head, slower ones get a 408, 0 = never
     */
    public function listen(
        string $address = 'http://0.0.0.0:8080',
        int $workers = 4,
        int $maxBodySize = 10 * 1024 * 1024,
        int $maxRequests = 0,
        float $idleTimeout = 60.0,
        float $headerTimeout = 20.0,
    ): Worker {
        if (!str_starts_with($address, 'http://')) {
            throw new InvalidArgumentException("Invalid address < $address >, expected http://host:port");
        }

        // tcp with the protocol set separately, as workerman would choose its own http protocol
        $worker = new Worker('tcp://' . substr($address, 7));
        $worker->protocol = HttpProtocol::class;
        $worker->name = 'http';
        $worker->count = $workers;
        // workers share one socket, so stopping workers doesn't drop queued connections
        $worker->reusePort = false;
        $worker->onWorkerStart = function (Worker $worker) use ($maxBodySize, $maxRequests, $idleTimeout, $headerTimeout): void {
            // workerman limits the whole request, the head is limited separately
            TcpConnection::$defaultMaxPackageSize = $maxBodySize + self::MAX_HEAD_SIZE;
            $this->maxRequests = $maxRequests;
            $this->timeouts = new ConnectionTimeouts($idleTimeout, $headerTimeout);
            Timer::add(1.0, fn() => $this->checkConnections($worker));
            // the process exits once its connections are closed, requests may still arrive after onWorkerStop
            register_shutdown_function($this->workerStopping(...));
            // create the handler at start, so errors show up early
            $this->handler();
        };
        // e.g. on a graceful reload, idle keep-alive connections would keep the worker alive
        $worker->onWorkerStop = function (): void {
            $this->draining = true;
        };
        $worker->onMessage = $this->handle(...);

        return $worker;
    }

    /**
     * @internal called by the worker for every request
     */
    public function handle(TcpConnection $connection, Request $request): void
    {
        if ($this->maxRequests > 0 && ++$this->requests === $this->maxRequests) {
            $this->drain();
        }

        try {
            $serverRequest = $this->requestConverter->convert($request, $connection);
        } catch (InvalidArgumentException $exception) {
            // e.g. an invalid host or header value, a client error
            $this->logger->info($exception->getMessage(), ['exception' => $exception]);
            $connection->end(self::BAD_REQUEST, true);
            return;
        } catch (Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $connection->end(self::SERVER_ERROR, true);
            return;
        }

        try {
            $response = $this->handler()->handle($serverRequest);
            /** @var string $connectionHeader */
            $connectionHeader = $this->draining ? 'close' : $request->header('connection', '');
            $this->responseSender->send($connection, $response, $request->method(), $request->protocolVersion(), $connectionHeader);
        } catch (Throwable $exception) {
            // handlers should render their errors, e.g. with an error middleware, so this is a bug
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $connection->end(self::SERVER_ERROR, true);
        }

        $this->requestHandled($serverRequest);
    }

    private function requestHandled(ServerRequestInterface $request): void
    {
        try {
            $this->lifecycle?->requestHandled($request);
        } catch (Throwable $exception) {
            // the state of the application is unknown, a new worker starts clean
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $this->drain();
        }
    }

    /**
     * Workerman stops the worker gracefully, the master starts a new one
     */
    private function drain(): void
    {
        if (!$this->draining) {
            $this->draining = true;
            posix_kill(posix_getpid(), SIGQUIT);
        }
    }

    private function workerStopping(): void
    {
        try {
            $this->lifecycle?->workerStopping();
        } catch (Throwable $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
        }
    }

    private function checkConnections(Worker $worker): void
    {
        $connections = [];
        /** @var TcpConnection $connection */
        foreach (TcpConnection::$connections as $connection) {
            // of this worker, not e.g. database clients
            if ($connection->worker === $worker) {
                $connections[] = $connection;
            }
        }

        $this->timeouts?->check($connections, $this->draining);
    }

    private function handler(): RequestHandlerInterface
    {
        if ($this->handler === null) {
            $this->handler = ($this->handlerFactory)();
            $this->lifecycle = $this->handler instanceof WorkerLifecycle ? $this->handler : null;
        }

        return $this->handler;
    }
}
