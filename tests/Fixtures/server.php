<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\PushStream;
use Semperton\Workerman\WorkerLifecycle;
use Workerman\Timer;
use Workerman\Worker;

/**
 * php server.php start <port> <maxRequests> <idleTimeout> <headerTimeout> <stopTimeout>
 * Responds with the pid of the worker, a 4 MB body for '/large', the request body for '/echo',
 * an event every 100 ms for '/events' ('/events-error' throws once the client is gone),
 * or whether workerStopping() was called for '/stopping'
 * workerStopping() writes the pid of the worker to <tmp>/semperton-workerman-<port>.stopping
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
$port = (int) ($argv[2] ?? 8080);
$maxRequests = (int) ($argv[3] ?? 0);
$idleTimeout = (float) ($argv[4] ?? 60);
$headerTimeout = (float) ($argv[5] ?? 20);
$stopTimeout = (float) ($argv[6] ?? 30);

$files = sys_get_temp_dir() . '/semperton-workerman-' . $port;
Worker::$pidFile = $files . '.pid';
Worker::$statusFile = $files . '.status';
Worker::$logFile = $files . '.log';

$handler = new class ($files . '.stopping') implements RequestHandlerInterface, WorkerLifecycle {
    private bool $stopping = false;

    public function __construct(
        private readonly string $stoppingFile,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/large' => new Response(body: str_repeat('0123456789abcdef', 256 * 1024)),
            '/echo' => new Response(body: $request->getBody()),
            '/events' => new Response(200, ['Content-Type' => 'text/event-stream'], $this->events()),
            '/events-error' => new Response(200, ['Content-Type' => 'text/event-stream'], $this->events(fail: true)),
            '/stopping' => new Response(body: $this->stopping ? 'yes' : 'no'),
            default => new Response(body: (string) getmypid()),
        };
    }

    public function requestHandled(ServerRequestInterface $request): void {}

    public function workerStopping(): void
    {
        $this->stopping = true;
        file_put_contents($this->stoppingFile, (string) getmypid());
    }

    private function events(bool $fail = false): PushStream
    {
        $stream = new PushStream();
        if ($fail) {
            $stream->onClose(static fn() => throw new RuntimeException('Failed'));
        }
        $count = 0;
        $timer = Timer::add(0.1, static function () use ($stream, &$count): void {
            $stream->push('data: ' . ++$count . "\n\n");
        });
        $stream->onClose(static fn() => Timer::del($timer));

        return $stream;
    }
};

$server = new HttpServer(static fn() => $handler);
$server->listen(
    "http://127.0.0.1:$port",
    workers: 1,
    maxBodySize: 1024,
    maxRequests: $maxRequests,
    idleTimeout: $idleTimeout,
    headerTimeout: $headerTimeout,
    stopTimeout: $stopTimeout,
);

Worker::runAll();
