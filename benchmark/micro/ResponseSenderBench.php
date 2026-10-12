<?php

declare(strict_types=1);

namespace Semperton\Workerman\Benchmark;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PhpBench\Attributes\Revs;
use Semperton\Workerman\Internal\ResponseSender;
use Semperton\Workerman\Tests\Fixtures\ReportedSizeStream;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;

use function str_repeat;

/**
 * Sends responses to a socket pair, the client side is drained after every response
 */
final class ResponseSenderBench
{
    private ResponseSender $sender;
    private SocketConnection $socket;
    private string $large;

    public function __construct()
    {
        $this->sender = new ResponseSender();
        $this->socket = new SocketConnection();
        $this->large = str_repeat('0123456789abcdef', 256 * 1024);
    }

    public function benchSmall(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], '{"id":1}');
        $this->sender->send($this->socket->connection, $response, 'GET', '1.1', 'keep-alive');
        $this->socket->read();
    }

    #[Revs(50)]
    public function benchLarge(): void
    {
        $this->sender->send($this->socket->connection, new Response(200, [], $this->large), 'GET', '1.1', 'keep-alive');
        $this->socket->read();
    }

    #[Revs(50)]
    public function benchChunked(): void
    {
        $body = new ReportedSizeStream(Stream::create($this->large));
        $this->sender->send($this->socket->connection, new Response(200, [], $body), 'GET', '1.1', 'keep-alive');
        $this->socket->read();
    }
}
