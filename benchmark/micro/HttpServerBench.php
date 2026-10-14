<?php

declare(strict_types=1);

namespace Semperton\Workerman\Benchmark;

use Bench\App;
use Closure;
use ReflectionMethod;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;

/**
 * A request through the whole server: read and parsed by Workerman, converted, handled by the benchmark app, sent and read by the client
 * Every request has another query, Workerman's caches would skip the parsing of repeated requests, unlike in real traffic
 */
final class HttpServerBench
{
    private const JSON_HEADERS = "Host: localhost:8080\r\n"
        . "User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0\r\n"
        . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
        . "Accept-Language: en-US,en;q=0.5\r\n"
        . "Accept-Encoding: gzip, deflate, br\r\n"
        . "Referer: https://example.com/users\r\n"
        . "Cookie: session=0123456789abcdef; theme=dark\r\n\r\n";

    private const FORM = "Host: localhost:8080\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . "Content-Length: 58\r\n\r\n"
        . 'name=Ann&email=ann%40example.com&tags%5B%5D=a&tags%5B%5D=b';

    private SocketConnection $socket;
    private int $request = 0;

    public function __construct()
    {
        require_once __DIR__ . '/../app/App.php';

        $server = new HttpServer(static fn() => new App());
        /** @var Closure $handle */
        $handle = (new ReflectionMethod(HttpServer::class, 'handle'))->getClosure($server);

        $this->socket = new SocketConnection();
        $connection = $this->socket->connection;
        // set by HttpServer when a client connects
        $connection->maxPackageSize = 1024 * 1024 + 16 * 1024;
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $connection->context->maxBodySize = 1024 * 1024;
        $connection->onMessage = $handle;
    }

    public function benchPlaintext(): void
    {
        $this->socket->write('GET /?r=' . ++$this->request . " HTTP/1.1\r\nHost: localhost:8080\r\n\r\n");
        $this->socket->read();
    }

    public function benchJson(): void
    {
        $this->socket->write('GET /json?page=2&sort=name&tags[]=a&tags[]=b&r=' . ++$this->request . " HTTP/1.1\r\n" . self::JSON_HEADERS);
        $this->socket->read();
    }

    public function benchForm(): void
    {
        $this->socket->write('POST /form?r=' . ++$this->request . " HTTP/1.1\r\n" . self::FORM);
        $this->socket->read();
    }
}
