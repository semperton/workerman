<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function microtime;
use function preg_match;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function str_repeat;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;
use const SIGINT;

/**
 * Runs the server in real Workerman processes, see Fixtures/server.php
 */
final class ServerTest extends TestCase
{
    private const GET = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

    /** @var resource */
    private static $process;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        [self::$process, self::$port] = self::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::stop(self::$process);
    }

    public function testKeepAlive(): void
    {
        $client = self::connect(self::$port);

        [$head, $pid] = self::request($client, self::GET);
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $head);
        self::assertStringNotContainsString('Connection: close', $head);

        // same connection, same worker
        self::assertSame($pid, self::request($client, self::GET)[1]);
    }

    public function testLargeBody(): void
    {
        [$head, $body] = self::request(self::connect(self::$port), "GET /large HTTP/1.1\r\nHost: localhost\r\n\r\n");

        self::assertStringContainsString("\r\nContent-Length: 4194304", $head);
        self::assertSame(str_repeat('0123456789abcdef', 256 * 1024), $body);
    }

    public function testBodyTooLarge(): void
    {
        $client = self::connect(self::$port);
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1048576\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 413 Payload Too Large', self::read($client));
    }

    public function testMaxRequests(): void
    {
        [$process, $port] = self::start(maxRequests: 2);

        try {
            $idle = self::connect($port);
            [, $pid] = self::request($idle, self::GET);

            [$head] = self::request(self::connect($port), self::GET);
            self::assertStringContainsString("\r\nConnection: close", $head);

            // the idle keep-alive connection is closed, so the worker restarts
            self::assertSame('', self::read($idle));
            self::assertTrue(feof($idle), 'closed, not timed out');
            self::assertNotSame($pid, self::request(self::connect($port), self::GET)[1]);
        } finally {
            self::stop($process);
        }
    }

    /**
     * @return array{resource, int}
     */
    private static function start(int $maxRequests = 0): array
    {
        // a free port, assigned by the os
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Unable to find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $output = ['file', sys_get_temp_dir() . "/semperton-workerman-$port.out", 'w'];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/server.php', 'start', (string) $port, (string) $maxRequests],
            [['file', '/dev/null', 'r'], $output, $output],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('Unable to start the server');
        }

        // wait for the workers
        $timeout = microtime(true) + 5.0;
        while (($client = @stream_socket_client("tcp://127.0.0.1:$port")) === false) {
            if (microtime(true) > $timeout) {
                self::stop($process);
                throw new RuntimeException("The server didn't start");
            }
            usleep(50_000);
        }
        fclose($client);

        return [$process, $port];
    }

    /**
     * @param resource $process
     */
    private static function stop($process): void
    {
        proc_terminate($process, SIGINT);
        proc_close($process);
    }

    /**
     * @return resource
     */
    private static function connect(int $port)
    {
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        if ($client === false) {
            throw new RuntimeException('Unable to connect');
        }
        stream_set_timeout($client, 5);

        return $client;
    }

    /**
     * Sends a request and reads the response, the connection stays open
     *
     * @param resource $client
     * @return array{string, string}
     */
    private static function request($client, string $request): array
    {
        fwrite($client, $request);

        $data = '';
        while (!str_contains($data, "\r\n\r\n")) {
            $data .= self::chunk($client);
        }

        $pos = (int) strpos($data, "\r\n\r\n");
        $head = substr($data, 0, $pos);
        $body = substr($data, $pos + 4);
        $length = preg_match('/\r\nContent-Length: (\d+)/i', $head, $matches) === 1 ? (int) $matches[1] : 0;

        while (strlen($body) < $length) {
            $body .= self::chunk($client);
        }

        return [$head, $body];
    }

    /**
     * Reads until the server closes the connection
     *
     * @param resource $client
     */
    private static function read($client): string
    {
        $data = '';
        while (($chunk = (string) fread($client, 65536)) !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param resource $client
     */
    private static function chunk($client): string
    {
        $chunk = (string) fread($client, 65536);
        if ($chunk === '') {
            throw new RuntimeException('The connection was closed or timed out');
        }

        return $chunk;
    }
}
