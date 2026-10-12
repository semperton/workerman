<?php

declare(strict_types=1);

namespace Semperton\Workerman\Benchmark;

use Semperton\Workerman\Internal\RequestConverter;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

/**
 * Converts typical browser requests, Workerman parses the buffer lazily, so a new request is created per revolution
 */
final class RequestConverterBench
{
    private const GET = "GET /users/1?page=2&sort=name HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0\r\n"
        . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
        . "Accept-Language: en-US,en;q=0.5\r\n"
        . "Accept-Encoding: gzip, deflate, br\r\n"
        . "Referer: https://example.com/users\r\n"
        . "Cookie: session=0123456789abcdef; theme=dark\r\n"
        . "Connection: keep-alive\r\n"
        . "Upgrade-Insecure-Requests: 1\r\n"
        . "Sec-Fetch-Dest: document\r\n"
        . "Sec-Fetch-Mode: navigate\r\n\r\n";

    private const POST = "POST /users HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . "Content-Length: 37\r\n\r\n"
        . "name=Ann&email=ann%40example.com&age=3";

    private RequestConverter $converter;
    private TcpConnection $connection;

    public function __construct()
    {
        $this->converter = new RequestConverter();
        $this->connection = (new SocketConnection())->connection;
    }

    public function benchGet(): void
    {
        $this->converter->convert(new Request(self::GET), $this->connection);
    }

    public function benchFormPost(): void
    {
        $this->converter->convert(new Request(self::POST), $this->connection);
    }
}
