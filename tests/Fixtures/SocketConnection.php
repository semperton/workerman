<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;

use function fread;
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

        $this->connection = new TcpConnection(new Select(), $server, $remoteAddress);
    }

    /**
     * The client disconnects
     */
    public function close(): void
    {
        stream_socket_shutdown($this->client, STREAM_SHUT_RDWR);
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
        /** @var resource $socket */
        $socket = $this->connection->getSocket();
        $read = $except = null;
        $write = [$socket];

        return stream_select($read, $write, $except, 0) === 1;
    }
}
