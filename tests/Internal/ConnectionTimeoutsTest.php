<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Internal;

use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Internal\ConnectionTimeouts;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;

use function str_repeat;

/**
 * The clock is set by the tests, connections are new when they are first checked
 */
final class ConnectionTimeoutsTest extends TestCase
{
    private float $now = 1000.0;

    public function testIdle(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();

        $timeouts->check([$socket->connection]);
        $this->now += 59.0;
        $timeouts->check([$socket->connection]);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());

        $this->now += 1.0;
        $timeouts->check([$socket->connection]);
        self::assertSame('', $socket->read(), 'closed silently');
        self::assertTrue($socket->ended());
    }

    public function testActivity(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();

        $timeouts->check([$socket->connection]);
        $this->now += 50.0;
        $socket->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $timeouts->check([$socket->connection]);
        $this->now += 50.0;
        $timeouts->check([$socket->connection]);

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testSlowHead(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        $socket->write("GET / HTTP/1.1\r\nHost: loc");
        self::assertNotNull($socket->connection->context);
        $socket->connection->context->headStartedAt = $this->now;

        // trickling data, but the head takes too long
        $this->now += 10.0;
        $socket->write('a');
        $timeouts->check([$socket->connection]);
        $this->now += 10.0;
        $socket->write('l');
        $timeouts->check([$socket->connection]);

        self::assertStringStartsWith("HTTP/1.1 408 Request Timeout\r\n", $socket->read());
        self::assertTrue($socket->ended());
    }

    public function testSlowBody(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        $socket->write("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\nhel");

        $timeouts->check([$socket->connection]);
        $this->now += 60.0;
        $timeouts->check([$socket->connection]);

        self::assertStringStartsWith("HTTP/1.1 408 Request Timeout\r\n", $socket->read());
    }

    public function testSlowReader(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        // a streamed response waits for the client
        $socket->connection->onBufferDrain = static function (): void {};

        $timeouts->check([$socket->connection]);
        $this->now += 59.0;
        $timeouts->check([$socket->connection]);
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());

        $this->now += 1.0;
        $timeouts->check([$socket->connection]);
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
    }

    public function testSlowReaderOfBufferedData(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        // more than the socket takes, e.g. a response sent at once, the rest waits in the send buffer
        $socket->connection->send(str_repeat('a', 4 * 1024 * 1024), true);
        self::assertGreaterThan(0, $socket->connection->getSendBufferQueueSize());

        $timeouts->check([$socket->connection]);
        $this->now += 60.0;
        $timeouts->check([$socket->connection]);

        // destroyed, a close would wait for the client to read the buffer
        self::assertSame(TcpConnection::STATUS_CLOSED, $socket->connection->getStatus());
    }

    public function testReaderProgress(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        $socket->connection->onBufferDrain = static function (): void {};

        // the client reads slowly, but it reads
        $timeouts->check([$socket->connection]);
        $this->now += 50.0;
        $socket->connection->bytesWritten += 1024;
        $timeouts->check([$socket->connection]);
        $this->now += 50.0;
        $timeouts->check([$socket->connection]);

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testSlowBodyAfterTheHead(): void
    {
        $socket = $this->socket();
        $timeouts = $this->timeouts();
        $socket->write("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\n\r\nhel");

        // the head is complete, only the idle timeout applies to the body
        $timeouts->check([$socket->connection]);
        $this->now += 30.0;
        $timeouts->check([$socket->connection]);

        self::assertSame('', $socket->read());
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $socket->connection->getStatus());
    }

    public function testCloseIdle(): void
    {
        $idle = $this->socket();
        $busy = $this->socket();
        $busy->write("GET / HTTP/1.1\r\n");

        $this->timeouts()->check([$idle->connection, $busy->connection], closeIdle: true);

        self::assertTrue($idle->ended());
        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $busy->connection->getStatus());
    }

    public function testDisabled(): void
    {
        $idle = $this->socket();
        // a slow head
        $receiving = $this->socket();
        $receiving->write("GET / HTTP/1.1\r\nHost: loc");
        // a slow reader
        $sending = $this->socket();
        $sending->connection->onBufferDrain = static function (): void {};
        $connections = [$idle->connection, $receiving->connection, $sending->connection];
        $timeouts = new ConnectionTimeouts(0, 0, fn(): float => $this->now);

        $timeouts->check($connections);
        $this->now += 86400.0;
        $timeouts->check($connections);

        foreach ($connections as $connection) {
            self::assertSame(TcpConnection::STATUS_ESTABLISHED, $connection->getStatus());
        }
        self::assertSame('', $receiving->read());
    }

    private function timeouts(): ConnectionTimeouts
    {
        return new ConnectionTimeouts(60, 20, fn(): float => $this->now);
    }

    /**
     * Requests are parsed, but not answered
     */
    private function socket(): SocketConnection
    {
        $socket = new SocketConnection();
        $socket->connection->onMessage = static function (): void {};

        return $socket;
    }
}
