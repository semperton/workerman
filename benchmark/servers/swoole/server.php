<?php

declare(strict_types=1);

use Bench\App;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require __DIR__ . '/vendor/autoload.php';

/**
 * Swoole has no PSR-7 bridge of its own, this one is what bridge libraries do
 * SWOOLE_BASE: every worker accepts and handles its connections, like workerman
 */

function toPsr(Request $request): ServerRequestInterface
{
    $server = $request->server;
    $query = $server['query_string'] ?? '';
    $uri = 'http://' . ($request->header['host'] ?? 'localhost') . $server['request_uri'] . ($query !== '' ? '?' . $query : '');
    $body = $request->rawContent();

    return (new ServerRequest(
        $server['request_method'],
        $uri,
        $request->header ?? [],
        $body === false || $body === '' ? null : $body,
        substr($server['server_protocol'], 5),
        array_change_key_case($server, CASE_UPPER),
    ))
        ->withQueryParams($request->get ?? [])
        ->withCookieParams($request->cookie ?? [])
        ->withParsedBody($request->post ?? null);
}

$app = new App();
$server = new Server('0.0.0.0', 8080, SWOOLE_BASE);
$server->set([
    'worker_num' => 4,
    'log_level' => SWOOLE_LOG_WARNING,
    'http_compression' => false,
]);
$server->on('request', static function (Request $request, Response $response) use ($app): void {
    $psrResponse = $app->handle(toPsr($request));

    $response->status($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());
    foreach ($psrResponse->getHeaders() as $name => $values) {
        $response->header((string) $name, implode(', ', $values));
    }
    $response->end((string) $psrResponse->getBody());
});
$server->start();
