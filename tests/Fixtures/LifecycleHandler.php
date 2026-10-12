<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semperton\Workerman\WorkerLifecycle;
use Throwable;

/**
 * Records its lifecycle calls, throws the given errors while handling, after a request or when the worker stops
 */
final class LifecycleHandler implements RequestHandlerInterface, WorkerLifecycle
{
    /** @var list<string> */
    private array $calls = [];

    public function __construct(
        private readonly ?Throwable $requestHandledError = null,
        private readonly ?Throwable $handleError = null,
        private readonly ?Throwable $workerStoppingError = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->handleError !== null) {
            throw $this->handleError;
        }
        return new Response();
    }

    public function requestHandled(ServerRequestInterface $request): void
    {
        if ($this->requestHandledError !== null) {
            throw $this->requestHandledError;
        }
        $this->calls[] = 'handled ' . $request->getUri()->getPath();
    }

    public function workerStopping(): void
    {
        $this->calls[] = 'stopping';
        if ($this->workerStoppingError !== null) {
            throw $this->workerStoppingError;
        }
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
