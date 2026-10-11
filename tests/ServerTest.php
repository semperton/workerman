<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Semperton\Workerman\Tests\Fixtures\Client;

use function fclose;
use function microtime;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_repeat;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;
use const SIGINT;
use const SIGUSR2;

/**
 * Runs the server in real Workerman processes, see Fixtures/server.php
 */
final class ServerTest extends TestCase
{
    private const GET = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const GET_LARGE = "GET /large HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const GET_EVENTS = "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const LARGE = '0123456789abcdef';

    /** @var resource */
    private static $process;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        // short timeouts, the shared server is idle between the tests
        [self::$process, self::$port] = self::start(idleTimeout: 2, headerTimeout: 1);
    }

    public static function tearDownAfterClass(): void
    {
        self::stop(self::$process);
    }

    public function testKeepAlive(): void
    {
        $client = new Client(self::$port);

        [$head, $pid] = $client->request(self::GET);
        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $head);
        self::assertStringNotContainsString('Connection: close', $head);

        // same connection, same worker
        self::assertSame($pid, $client->request(self::GET)[1]);
    }

    public function testLargeBody(): void
    {
        [$head, $body] = (new Client(self::$port))->request(self::GET_LARGE);

        self::assertStringContainsString("\r\nContent-Length: 4194304", $head);
        self::assertSame(str_repeat(self::LARGE, 256 * 1024), $body);
    }

    public function testPipelining(): void
    {
        $client = new Client(self::$port);
        // the streamed response pauses reading, the next requests wait for it
        $client->write(self::GET . self::GET_LARGE . "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\n\r\nping");

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $client->response()[0]);
        self::assertSame(str_repeat(self::LARGE, 256 * 1024), $client->response()[1]);
        self::assertSame('ping', $client->response()[1]);
    }

    public function testContinue(): void
    {
        $client = new Client(self::$port);
        $start = microtime(true);
        $client->write("POST /echo HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\n");

        // curl would wait a second without it
        self::assertSame('HTTP/1.1 100 Continue', $client->response()[0]);
        self::assertLessThan(0.5, microtime(true) - $start);

        $client->write('ping');
        self::assertSame('ping', $client->response()[1]);
    }

    public function testChunkedBody(): void
    {
        $client = new Client(self::$port);
        $request = "POST /echo HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n2\r\npi\r\n2\r\nng\r\n0\r\n\r\n";

        self::assertSame('ping', $client->request($request)[1]);
    }

    public function testBodyTooLarge(): void
    {
        $client = new Client(self::$port);
        $client->write("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1048576\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 413 Payload Too Large', $client->readAll());
    }

    public function testHeaderTimeout(): void
    {
        $client = new Client(self::$port);
        $client->write("GET / HTTP/1.1\r\nHost: loc");

        self::assertStringStartsWith('HTTP/1.1 408 Request Timeout', $client->readAll());
        self::assertTrue($client->closed(), 'closed, not timed out');
    }

    public function testIdleTimeout(): void
    {
        $client = new Client(self::$port);
        $client->request(self::GET);

        self::assertSame('', $client->readAll());
        self::assertTrue($client->closed(), 'closed, not timed out');
    }

    public function testMaxRequests(): void
    {
        [$process, $port] = self::start(maxRequests: 2);

        try {
            $idle = new Client($port);
            [, $pid] = $idle->request(self::GET);

            [$head] = (new Client($port))->request(self::GET);
            self::assertStringContainsString("\r\nConnection: close", $head);

            // the idle keep-alive connection is closed, so the worker restarts
            self::assertSame('', $idle->readAll());
            self::assertTrue($idle->closed(), 'closed, not timed out');
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
        } finally {
            self::stop($process);
        }
    }

    public function testPush(): void
    {
        $client = new Client(self::$port);
        [$head] = $client->request(self::GET_EVENTS);

        self::assertStringContainsString("\r\nTransfer-Encoding: chunked", $head);
        // pushed every 100 ms, longer than the idle timeout of 2 s would allow without them
        self::assertStringContainsString("data: 25\n\n", $client->readUntil("data: 25\n\n"));
    }

    public function testPushGracefulReload(): void
    {
        [$process, $port] = self::start();

        try {
            $events = new Client($port);
            $events->write(self::GET_EVENTS);
            $events->readUntil("data: 1\n\n");

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);

            // closed, so the worker can exit, clients reconnect
            $events->readAll();
            self::assertTrue($events->closed());
            self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", (new Client($port))->request(self::GET)[0]);
        } finally {
            self::stop($process);
        }
    }

    public function testGracefulReload(): void
    {
        [$process, $port] = self::start();

        try {
            $idle = new Client($port);
            [, $pid] = $idle->request(self::GET);

            // the client doesn't read yet, the response waits for it
            $busy = new Client($port);
            $busy->write(self::GET_LARGE);
            usleep(100_000);

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);

            self::assertSame(str_repeat(self::LARGE, 256 * 1024), $busy->response()[1], 'not dropped');
            self::assertSame('', $idle->readAll());
            self::assertTrue($idle->closed(), 'closed, so the worker can exit');
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
        } finally {
            self::stop($process);
        }
    }

    /**
     * @return array{resource, int}
     */
    private static function start(int $maxRequests = 0, float $idleTimeout = 60, float $headerTimeout = 20): array
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
            [PHP_BINARY, __DIR__ . '/Fixtures/server.php', 'start', (string) $port, (string) $maxRequests, (string) $idleTimeout, (string) $headerTimeout],
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
}
