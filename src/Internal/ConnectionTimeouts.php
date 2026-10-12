<?php

declare(strict_types=1);

namespace Semperton\Workerman\Internal;

use Closure;
use WeakMap;
use Workerman\Connection\TcpConnection;

use function microtime;

/**
 * Closes connections that make no progress, Workerman has no timeouts of its own
 * Without them, idle or slow clients (slowloris) would keep connections, and the worker, busy forever
 *
 * Checked about once a second, so the timeouts are precise to a second
 *
 * @internal
 */
final class ConnectionTimeouts
{
    private const REQUEST_TIMEOUT = "HTTP/1.1 408 Request Timeout\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    /** @var WeakMap<TcpConnection, array{read: int, written: int, active: float}> */
    private WeakMap $progress;

    /**
     * @param float $idleTimeout seconds without receiving or sending anything, 0 = never
     * @param float $headerTimeout seconds to receive a request head, 0 = never
     * @param ?Closure(): float $clock
     */
    public function __construct(
        private readonly float $idleTimeout,
        private readonly float $headerTimeout,
        private readonly ?Closure $clock = null,
    ) {
        /** @var WeakMap<TcpConnection, array{read: int, written: int, active: float}> */
        $progress = new WeakMap();
        $this->progress = $progress;
    }

    /**
     * @param iterable<TcpConnection> $connections of the worker
     * @param bool $closeIdle e.g. when the worker stops, busy connections are closed after their response
     */
    public function check(iterable $connections, bool $closeIdle = false): void
    {
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);

        foreach ($connections as $connection) {
            // closing connections finish on their own
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                continue;
            }

            $inactive = $now - $this->lastActive($connection, $now);
            $sending = $connection->onBufferDrain !== null || $connection->getSendBufferQueueSize() > 0;
            $receiving = $connection->getRecvBufferQueueSize() > 0;

            if ($sending) {
                // the client doesn't read the response
                if ($this->idleTimeout > 0 && $inactive >= $this->idleTimeout) {
                    $connection->destroy();
                }
            } elseif ($receiving) {
                /** @var ?float $headStartedAt set by HttpProtocol */
                $headStartedAt = $connection->context->headStartedAt ?? null;
                $slowHead = $this->headerTimeout > 0 && $headStartedAt !== null && $now - $headStartedAt >= $this->headerTimeout;
                $slowBody = $this->idleTimeout > 0 && $inactive >= $this->idleTimeout;

                if ($slowHead || $slowBody) {
                    $connection->end(self::REQUEST_TIMEOUT, true);
                }
            } elseif ($closeIdle || ($this->idleTimeout > 0 && $inactive >= $this->idleTimeout)) {
                // an idle keep-alive connection, closed silently like nginx does
                $connection->close();
            }
        }
    }

    /**
     * The time anything was last received or sent, connections are new when they are first seen
     */
    private function lastActive(TcpConnection $connection, float $now): float
    {
        $progress = $this->progress[$connection] ?? null;

        if (
            $progress === null
            || $progress['read'] !== $connection->bytesRead
            || $progress['written'] !== $connection->bytesWritten
        ) {
            $progress = ['read' => $connection->bytesRead, 'written' => $connection->bytesWritten, 'active' => $now];
            $this->progress[$connection] = $progress;
        }

        return $progress['active'];
    }
}
