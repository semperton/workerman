<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

/**
 * The app with Workerman's own request and response, no PSR-7, the ceiling for semperton
 */

Worker::$pidFile = '/tmp/workerman.pid';
Worker::$logFile = '/tmp/workerman.log';

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->onMessage = static function (TcpConnection $connection, Request $request): void {
    $response = match ($request->path()) {
        '/' => new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!'),
        '/json' => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'query' => $request->get(),
            'agent' => $request->header('user-agent', ''),
            'session' => $request->cookie('session'),
        ], JSON_THROW_ON_ERROR)),
        '/form' => new Response(200, ['Content-Type' => 'application/json'], json_encode($request->post(), JSON_THROW_ON_ERROR)),
        '/echo' => new Response(200, ['Content-Type' => 'application/octet-stream'], $request->rawBody()),
        default => new Response(404),
    };

    // workerman's response always keeps the connection alive
    if (strcasecmp($request->header('connection', ''), 'close') === 0) {
        $connection->close($response->withHeader('Connection', 'close'));
    } else {
        $connection->send($response);
    }
};

Worker::runAll();
