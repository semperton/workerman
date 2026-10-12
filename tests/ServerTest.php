<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Tests\Fixtures\Client;
use Semperton\Workerman\Tests\Fixtures\ServerProcess;

use function file_get_contents;
use function microtime;
use function posix_kill;
use function proc_get_status;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

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
        [$process, $port] = ServerProcess::start(idleTimeout: 0, stopTimeout: 1);

        try {
            [, $pid] = (new Client($port))->request(self::GET);

            // 1 GB, the os buffers e.g. 4 MB of it (loopback autotuning), the rest is still being sent
            $busy = new Client($port);
            $busy->write(self::GET_HUGE);
            usleep(100_000);

            posix_kill(proc_get_status($process)['pid'], SIGUSR2);
            usleep(200_000);

            // within the 5 s read timeout of the client
            self::assertNotSame($pid, (new Client($port))->request(self::GET)[1]);
            self::assertLessThan(1024 * 1024 * 1024, strlen($busy->readAll()), 'the response was cut');
            self::assertTrue($busy->closed());
        } finally {
            ServerProcess::stop($process);
        }
    }
}
