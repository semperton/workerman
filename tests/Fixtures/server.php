<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\PushStream;
use Workerman\Timer;
use Workerman\Worker;

/**
 * php server.php start <port> <maxRequests> <idleTimeout> <headerTimeout>
 * Responds with the pid of the worker, a 4 MB body for '/large', the request body for '/echo'
 * or an event every 100 ms for '/events'
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
$port = (int) ($argv[2] ?? 8080);
$maxRequests = (int) ($argv[3] ?? 0);
$idleTimeout = (float) ($argv[4] ?? 60);
$headerTimeout = (float) ($argv[5] ?? 20);

$files = sys_get_temp_dir() . '/semperton-workerman-' . $port;
Worker::$pidFile = $files . '.pid';
Worker::$statusFile = $files . '.status';
Worker::$logFile = $files . '.log';

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/large' => new Response(body: str_repeat('0123456789abcdef', 256 * 1024)),
            '/echo' => new Response(body: $request->getBody()),
            '/events' => new Response(200, ['Content-Type' => 'text/event-stream'], $this->events()),
            default => new Response(body: (string) getmypid()),
        };
    }

    private function events(): PushStream
    {
        $stream = new PushStream();
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
);

Worker::runAll();
