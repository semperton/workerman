<?php

declare(strict_types=1);

namespace Bench;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The application every server runs, through its PSR-7 bridge
 *
 * GET  /      plaintext, the overhead of the server
 * GET  /json  query, header and cookie params as JSON, the cost of converting a browser-like request
 * POST /form  the parsed form body as JSON
 * POST /echo  the raw body
 */
final class App implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/' => new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!'),
            '/json' => self::json([
                'query' => $request->getQueryParams(),
                'agent' => $request->getHeaderLine('User-Agent'),
                'session' => $request->getCookieParams()['session'] ?? null,
            ]),
            '/form' => self::json($request->getParsedBody()),
            '/echo' => new Response(200, ['Content-Type' => 'application/octet-stream'], (string) $request->getBody()),
            default => new Response(404),
        };
    }

    private static function json(mixed $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
