<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Semperton\Workerman\HttpServer;
use Workerman\Events\Select;
use Workerman\Worker;

/**
 * The target of the security tests, an echo server with small limits, see security/README.md
 *
 * ECHO=probe   the endpoints of Http11Probe: GET / is 'OK', POST / the body, /echo the headers, /cookie the cookies
 * ECHO=garden  every request as JSON for HTTP Garden: method, uri, version, headers and body, base64-encoded
 *
 * php server.php start [port]
 */

require __DIR__ . '/vendor/autoload.php';

final class ProbeEcho implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $lines = match ($request->getUri()->getPath()) {
            '/' => null,
            '/echo' => self::lines($request->getHeaders(), ': '),
            '/cookie' => self::lines(array_map(static fn(mixed $value) => [(string) $value], $request->getCookieParams()), '='),
            default => false,
        };

        return match (true) {
            $lines === false => new Response(404),
            $lines !== null => new Response(200, ['Content-Type' => 'text/plain'], $lines),
            $request->getMethod() === 'POST' => new Response(200, ['Content-Type' => 'text/plain'], $request->getBody()),
            default => new Response(200, ['Content-Type' => 'text/plain'], 'OK'),
        };
    }

    /**
     * @param array<array-key, list<string>> $values
     */
    private static function lines(array $values, string $separator): string
    {
        $lines = [];
        foreach ($values as $name => $list) {
            foreach ($list as $value) {
                $lines[] = $name . $separator . $value;
            }
        }

        return implode("\n", $lines);
    }
}

final class GardenEcho implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = [base64_encode((string) $name), base64_encode($value)];
            }
        }

        $echo = [
            'method' => base64_encode($request->getMethod()),
            'uri' => base64_encode((string) ($request->getServerParams()['REQUEST_URI'] ?? '')),
            'version' => base64_encode('HTTP/' . $request->getProtocolVersion()),
            'headers' => $headers,
            'body' => base64_encode((string) $request->getBody()),
        ];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($echo, JSON_THROW_ON_ERROR));
    }
}

/** @var list<string> $argv */
$port = (int) ($argv[2] ?? 8080);
$handler = getenv('ECHO') === 'garden' ? new GardenEcho() : new ProbeEcho();

// workerman uses the event extension if loaded, select is its fallback
if (getenv('EVENT_LOOP') === 'select') {
    Worker::$eventLoopClass = Select::class;
}
Worker::$pidFile = sys_get_temp_dir() . "/semperton-security-$port.pid";
Worker::$logFile = sys_get_temp_dir() . "/semperton-security-$port.log";

$server = new HttpServer(static fn() => $handler);
$server->listen(
    "http://0.0.0.0:$port",
    workers: 2,
    // small limits, so the tests reach them
    maxBodySize: 64 * 1024,
    idleTimeout: 10.0,
    headerTimeout: 5.0,
    stopTimeout: 2.0,
);

Worker::runAll();
