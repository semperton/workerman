<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Tests\Fixtures\Client;
use Semperton\Workerman\Tests\Fixtures\ServerProcess;

use function fclose;
use function file_get_contents;
use function json_decode;
use function microtime;
use function mkdir;
use function posix_kill;
use function proc_get_status;
use function rmdir;
use function str_repeat;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const SIGUSR2;

/**
 * Runs the server in real Workerman processes, see Fixtures/server.php
 */
final class ServerTest extends TestCase
{
    private const GET = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const GET_LARGE = "GET /large HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const GET_HUGE = "GET /huge HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const GET_EVENTS = "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n";
    private const LARGE = '0123456789abcdef';

    /** @var resource */
    private static $process;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        // short timeouts, the shared server is idle between the tests
        [self::$process, self::$port] = ServerProcess::start(idleTimeout: 2, headerTimeout: 1);
    }

    public static function tearDownAfterClass(): void
    {
        ServerProcess::stop(self::$process);
    }

    public function testPipelining(): void
    {
        $client = new Client(self::$port);
        // the responses are sent in order, the large one is sent at once, see ResponseSenderTest for streamed ones
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

    public function testHttp10WithoutHost(): void
    {
        $client = new Client(self::$port);
        [, $body] = $client->request("GET /server HTTP/1.0\r\n\r\n");

        // the local address is the host, in any order
        self::assertEquals([
            'uri' => 'http://127.0.0.1:' . self::$port . '/server',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_PORT' => self::$port,
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testChunkedBody(): void
    {
        $client = new Client(self::$port);
        $request = "POST /echo HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n2\r\npi\r\n2\r\nng\r\n0\r\n\r\n";

        self::assertSame('ping', $client->request($request)[1]);
    }

    public function testPushListenerError(): void
    {
        [, $pid] = (new Client(self::$port))->request(self::GET);

        $events = new Client(self::$port);
        $events->write("GET /events-error HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $events->readUntil("data: 1\n\n");
        // the listener throws once the client is gone
        unset($events);
        usleep(200_000);

        self::assertSame($pid, (new Client(self::$port))->request(self::GET)[1], 'the worker is still running');
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
        [$process, $port] = ServerProcess::start(maxRequests: 2);

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
            ServerProcess::stop($process);
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
        [$process, $port] = ServerProcess::start();

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
            ServerProcess::stop($process);
        }
    }

    public function testGracefulReload(): void
    {
        [$process, $port] = ServerProcess::start();

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
            ServerProcess::stop($process);
        }
    }

    public function testWorkerStoppingAfterLastRequest(): void
    {
        [$process, $port] = ServerProcess::start();
        $stoppingFile = sys_get_temp_dir() . "/semperton-workerman-$port.stopping";

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            // the request is still arriving when the worker starts to stop
            $client = new Client($port);
            $client->write("POST /stopping HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\n\r\npi");
            usleep(100_000);
            posix_kill(proc_get_status($process)['pid'], SIGUSR2);
            usleep(200_000);
            $client->write('ng');

            [$head, $body] = $client->response();
            self::assertSame('no', $body, 'not stopping while requests are handled');
            self::assertStringContainsString("\r\nConnection: close", $head);

            // a new worker answers once the old one has exited
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
            self::assertSame($pid, @file_get_contents($stoppingFile), 'called when the worker exits');
        } finally {
            ServerProcess::stop($process);
            @unlink($stoppingFile);
        }
    }

    public function testStopTimeout(): void
    {
        // without an idle timeout, only the stop timeout ends a client that doesn't read
        [$process, $port] = ServerProcess::start(idleTimeout: 0, stopTimeout: 2);

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            // 1 GB, the os buffers e.g. 4 MB of it (loopback autotuning), the rest is still being sent
            $busy = new Client($port);
            $busy->write(self::GET_HUGE);
            usleep(100_000);

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);
            $stopped = microtime(true);
            usleep(200_000);

            // the new worker answers once the old one has exited, within the 5 s read timeout of the client
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
            self::assertGreaterThanOrEqual(1.9, microtime(true) - $stopped, 'waited for the busy connection');
            self::assertLessThan(1024 * 1024 * 1024, strlen($busy->readAll()), 'the response was cut');
            self::assertTrue($busy->closed());
        } finally {
            ServerProcess::stop($process);
        }
    }

    public function testFileMonitorReload(): void
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('semperton-monitor-');
        mkdir($dir);
        [$process, $port] = ServerProcess::start(monitor: $dir);

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            // checked every 100 ms, the workers are reloaded gracefully
            touch($dir . '/file.php', time() + 10);
            $deadline = microtime(true) + 5.0;
            do {
                usleep(100_000);
                [, $current] = (new Client($port))->request(self::GET);
            } while ($current === $pid && microtime(true) < $deadline);

            self::assertNotSame($pid, $current);
        } finally {
            ServerProcess::stop($process);
            @unlink($dir . '/file.php');
            @rmdir($dir);
        }
    }

    public function testClientClosedWhenStopping(): void
    {
        [$database, $databasePort] = self::database();
        [$process, $port] = ServerProcess::start(clientPort: $databasePort);
        $stoppingFile = sys_get_temp_dir() . "/semperton-workerman-$port.stopping";

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);
            usleep(200_000);

            // workerman waits for the client, workerStopping() closes it once the last request is handled
            // within the 5 s read timeout of the client, far below the stop timeout of 30 s
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
            self::assertSame($pid, @file_get_contents($stoppingFile));
        } finally {
            ServerProcess::stop($process);
            fclose($database);
            @unlink($stoppingFile);
        }
    }

    public function testClientKeptWhenStopping(): void
    {
        [$database, $databasePort] = self::database();
        [$process, $port] = ServerProcess::start(stopTimeout: 1, clientPort: $databasePort, keepClient: true);
        $stoppingFile = sys_get_temp_dir() . "/semperton-workerman-$port.stopping";

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);
            usleep(200_000);

            // the client would keep the worker forever, it exits after the stop timeout
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
            self::assertSame($pid, @file_get_contents($stoppingFile));
        } finally {
            ServerProcess::stop($process);
            fclose($database);
            @unlink($stoppingFile);
        }
    }

    /**
     * A server like a database, the os completes connections without accepting them
     *
     * @return array{resource, int}
     */
    private static function database(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);

        return [$socket, (int) substr($name, (int) strrpos($name, ':') + 1)];
    }
}
