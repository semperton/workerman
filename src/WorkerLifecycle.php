<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Optionally implemented by the request handler, which lives as long as its worker
 *
 * @api
 */
interface WorkerLifecycle
{
    /**
     * Called once the response was handed to the connection, e.g. to reset services or clear caches
     * Streamed and pushed bodies are still being sent, they must not rely on the state that is reset
     * If it throws, the worker restarts gracefully, as its state is unknown
     */
    public function requestHandled(ServerRequestInterface $request): void;

    /**
     * Called when the worker stops (reload, maxRequests, shutdown), e.g. to close connections
     */
    public function workerStopping(): void;
}
