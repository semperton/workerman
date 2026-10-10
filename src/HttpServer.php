<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Closure;
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

use const SIGQUIT;

/**
 * Serves a PSR-15 request handler with Workerman
 * Every worker process creates the handler once and handles all its requests with it
 */
final class HttpServer
{
    /** Workerman answers larger request heads with a 431 */
    private const MAX_HEAD_SIZE = 16 * 1024;

    private readonly ResponseSender $responseSender;
    private ?RequestHandlerInterface $handler = null;
    private int $maxRequests = 0;
    private int $requests = 0;

    /**
     * @param Closure(): RequestHandlerInterface $handlerFactory called in every worker, after the fork,
     * so connections (e.g. to databases) are not shared between processes
     */
    public function __construct(
        private readonly Closure $handlerFactory,
        private readonly RequestConverter $requestConverter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->responseSender = new ResponseSender($logger);
    }

    /**
     * Can be called more than once, every worker process runs one of the returned workers with its own options
     *
     * @param int $maxBodySize requests with a larger body get a 413, the body is kept in memory
     * @param int $maxRequests a worker restarts after this many requests, limits slow memory leaks, 0 = never
     */
    public function listen(
        string $address = 'http://0.0.0.0:8080',
        int $workers = 4,
        int $maxBodySize = 10 * 1024 * 1024,
        int $maxRequests = 0,
    ): Worker {
        $worker = new Worker($address);
        $worker->name = 'http';
        $worker->count = $workers;
        // workers share one socket, so stopping workers doesn't drop queued connections
        $worker->reusePort = false;
        $worker->onWorkerStart = function () use ($maxBodySize, $maxRequests): void {
            // workerman limits the whole request, the head is limited separately
            TcpConnection::$defaultMaxPackageSize = $maxBodySize + self::MAX_HEAD_SIZE;
            $this->maxRequests = $maxRequests;
            // create the handler at start, so errors show up early
            $this->handler();
        };
        $worker->onMessage = $this->handle(...);

        return $worker;
    }

    /**
     * @internal called by the worker for every request
     */
    public function handle(TcpConnection $connection, Request $request): void
    {
        $method = $request->method();
        /** @var string $connectionHeader */
        $connectionHeader = $request->header('connection', '');

        if ($this->maxRequests > 0 && ++$this->requests >= $this->maxRequests) {
            $connectionHeader = 'close';
            if ($this->requests === $this->maxRequests) {
                $this->stop($connection);
            }
        }

        try {
            $serverRequest = $this->requestConverter->convert($request, $connection);
        } catch (Throwable $exception) {
            // e.g. an invalid host, a client error
            $this->logger->info($exception->getMessage(), ['exception' => $exception]);
            $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", true);
            return;
        }

        try {
            $response = $this->handler()->handle($serverRequest);
            $this->responseSender->send($connection, $response, $method, $request->protocolVersion(), $connectionHeader);
        } catch (Throwable $exception) {
            // handlers should render their errors, e.g. with an error middleware, so this is a bug
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $connection->close("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", true);
        }
    }

    /**
     * The worker stops accepting connections and exits once they are closed, the master starts a new one
     * Idle keep-alive connections would keep it alive, so they are closed, busy ones are closed after their response
     */
    private function stop(TcpConnection $connection): void
    {
        posix_kill(posix_getpid(), SIGQUIT);

        $worker = $connection->worker;
        if ($worker === null) {
            return;
        }

        Timer::add(1.0, static function () use ($worker): void {
            /** @var TcpConnection $connection */
            foreach (TcpConnection::$connections as $connection) {
                // of this worker (not e.g. database clients), no request received and no response streaming
                if (
                    $connection->worker === $worker
                    && $connection->getRecvBufferQueueSize() === 0
                    && $connection->onBufferDrain === null
                ) {
                    $connection->close();
                }
            }
        });
    }

    private function handler(): RequestHandlerInterface
    {
        return $this->handler ??= ($this->handlerFactory)();
    }
}
