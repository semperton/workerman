<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function preg_match;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function strpos;
use function substr;

/**
 * A blocking HTTP/1.1 client for the server tests, reads exactly one response at a time,
 * so pipelined responses stay buffered
 */
final class Client
{
    /** @var resource */
    private $socket;
    private string $buffer = '';

    public function __construct(int $port)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:$port");
        if ($socket === false) {
            throw new RuntimeException('Unable to connect');
        }
        stream_set_timeout($socket, 5);
        $this->socket = $socket;
    }

    public function __destruct()
    {
        fclose($this->socket);
    }

    public function write(string $data): void
    {
        fwrite($this->socket, $data);
    }

    /**
     * @return array{string, string} head and body, the connection stays open
     */
    public function request(string $request): array
    {
        $this->write($request);

        return $this->response();
    }

    /**
     * Reads one response with a Content-Length (or none, like 100 Continue)
     *
     * @return array{string, string}
     */
    public function response(): array
    {
        $pos = strpos($this->buffer, "\r\n\r\n");
        while ($pos === false) {
            $this->buffer .= $this->chunk();
            $pos = strpos($this->buffer, "\r\n\r\n");
        }

        $head = substr($this->buffer, 0, $pos);
        $length = preg_match('/\r\nContent-Length: (\d+)/i', $head, $matches) === 1 ? (int) $matches[1] : 0;

        while (strlen($this->buffer) < $pos + 4 + $length) {
            $this->buffer .= $this->chunk();
        }

        $body = substr($this->buffer, $pos + 4, $length);
        $this->buffer = substr($this->buffer, $pos + 4 + $length);

        return [$head, $body];
    }

    /**
     * Reads until the data contains the needle, e.g. of a pushed body
     */
    public function readUntil(string $needle): string
    {
        while (!str_contains($this->buffer, $needle)) {
            $this->buffer .= $this->chunk();
        }

        $data = $this->buffer;
        $this->buffer = '';

        return $data;
    }

    /**
     * Reads until the server closes the connection, or the read times out
     */
    public function readAll(): string
    {
        $data = $this->buffer;
        $this->buffer = '';

        while (($chunk = (string) fread($this->socket, 65536)) !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Closed by the server, not timed out, after readAll()
     */
    public function closed(): bool
    {
        return feof($this->socket);
    }

    private function chunk(): string
    {
        $chunk = (string) fread($this->socket, 65536);
        if ($chunk === '') {
            throw new RuntimeException('The connection was closed or timed out');
        }

        return $chunk;
    }
}
