<?php

declare(strict_types=1);

namespace Semperton\Workerman\Benchmark;

use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Semperton\Workerman\Internal\HttpProtocol;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;

use function dechex;
use function intdiv;
use function min;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Finds the end of requests like Workerman's connection does: input() with the buffer of every read until the request is complete, then decode()
 */
final class HttpProtocolBench
{
    private const READ_SIZE = 64 * 1024;

    private const HEAD = "Host: localhost:8080\r\n"
        . "User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0\r\n"
        . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
        . "Accept-Language: en-US,en;q=0.5\r\n"
        . "Accept-Encoding: gzip, deflate, br\r\n"
        . "Cookie: session=0123456789abcdef; theme=dark\r\n\r\n";

    private TcpConnection $connection;
    private int $request = 0;
    private string $smallChunks;
    private string $chunks;

    public function __construct()
    {
        $this->connection = (new SocketConnection())->connection;
        $this->connection->maxPackageSize = 1024 * 1024 + 16 * 1024;
        /** @psalm-suppress PossiblyNullPropertyAssignment the context is always set */
        $this->connection->context->maxBodySize = 1024 * 1024;

        $this->smallChunks = self::chunked(256 * 1024, 1);
        $this->chunks = self::chunked(960 * 1024, 1024);
    }

    /**
     * Every head is another one, Workerman's caches would skip the parsing of repeated requests
     */
    public function benchHead(): void
    {
        HttpProtocol::input('GET /?r=' . ++$this->request . " HTTP/1.1\r\n" . self::HEAD, $this->connection);
    }

    #[Revs(1)]
    #[Iterations(3)]
    public function benchSmallChunks(): void
    {
        $this->read($this->smallChunks);
    }

    #[Revs(5)]
    public function benchChunks(): void
    {
        $this->read($this->chunks);
    }

    private function read(string $request): void
    {
        $length = 0;
        for ($read = self::READ_SIZE; $length === 0; $read += self::READ_SIZE) {
            $length = HttpProtocol::input(substr($request, 0, min($read, strlen($request))), $this->connection);
        }

        HttpProtocol::decode(substr($request, 0, $length), $this->connection);
    }

    /**
     * A body of about the given size, sent in chunks of the given size
     */
    private static function chunked(int $size, int $chunkSize): string
    {
        $chunk = dechex($chunkSize) . "\r\n" . str_repeat('a', $chunkSize) . "\r\n";

        return "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n"
            . str_repeat($chunk, intdiv($size, strlen($chunk))) . "0\r\n\r\n";
    }
}
