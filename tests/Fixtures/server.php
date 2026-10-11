<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semperton\Workerman\HttpServer;
use Workerman\Worker;

/**
 * php server.php start <port> <maxRequests> <idleTimeout> <headerTimeout>
 * Responds with the pid of the worker, a 4 MB body for '/large' or the request body for '/echo'
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
            default => new Response(body: (string) getmypid()),
        };
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
