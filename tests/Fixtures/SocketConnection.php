<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;
use Semperton\Workerman\Internal\HttpProtocol;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;

use function feof;
use function fread;
use function fwrite;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_pair;
use function stream_socket_shutdown;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SHUT_RDWR;
use const STREAM_SOCK_STREAM;

/**
 * A Workerman connection with a real socket, the other end acts as the client
 * Received data is parsed with the server's protocol, data sent with raw = false is encoded by it, like in the server
 */
final class SocketConnection
{
    public readonly TcpConnection $connection;

    /** @var resource */
    private $client;

    public function __construct(string $remoteAddress = '127.0.0.1:50000')
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create a socket pair');
        }

        [$server, $this->client] = $sockets;
        stream_set_blocking($this->client, false);

        // the loop doesn't run, timers (e.g. of a graceful close) are only registered
        $eventLoop = new Select();
        Timer::init($eventLoop);
        $this->connection = new TcpConnection($eventLoop, $server, $remoteAddress);
        $this->connection->protocol = HttpProtocol::class;
    }

    /**
     * The client disconnects
     */
    public function close(): void
    {
        stream_socket_shutdown($this->client, STREAM_SHUT_RDWR);
    }

    /**
     * The client sends data, the connection reads it like the event loop would
     */
    public function write(string $data): void
    {
        fwrite($this->client, $data);
        $this->connection->baseRead($this->connection->getSocket());
    }

    /**
     * The server has closed the connection, or at least its sending side, after all data was read
     */
    public function ended(): bool
    {
        return feof($this->client);
    }

    /**
     * Reads all data sent so far, flushes the send buffer of the connection meanwhile
     * Like the event loop, the buffer is only flushed when the socket is writable
     */
    public function read(): string
    {
        $data = '';

        do {
            $chunk = (string) fread($this->client, 65536);
            $data .= $chunk;

            $buffered = $this->connection->getSendBufferQueueSize() > 0;
            if ($buffered && $this->writable()) {
                $this->connection->baseWrite();
            }
        } while ($chunk !== '' || $buffered);

        return $data;
    }

    private function writable(): bool
    {
        $socket = $this->connection->getSocket();
        $read = $except = null;
        $write = [$socket];

        return stream_select($read, $write, $except, 0) === 1;
    }
}
