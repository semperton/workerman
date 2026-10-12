<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semperton\Workerman\Tests\Fixtures\Client;
use Semperton\Workerman\Tests\Fixtures\ServerProcess;

use function preg_match;

/**
 * Sends the requests of requests.php to a real server, see Fixtures/server.php
 * A connection that stays open must answer a further request correctly, a desync would answer it with another response
 */
final class RawRequestTest extends TestCase
{
    private const FOLLOW_UP = "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 6\r\n\r\nfollow";

    /** @var resource */
    private static $process;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        // short timeouts, incomplete requests get a 408
        [self::$process, self::$port] = ServerProcess::start(idleTimeout: 2, headerTimeout: 1);
    }

    public static function tearDownAfterClass(): void
    {
        ServerProcess::stop(self::$process);
    }

    /**
     * @return array<string, array{string, list<int>, bool}>
     */
    public static function requests(): array
    {
        /** @var array<string, array{string, list<int>, bool}> */
        return require __DIR__ . '/requests.php';
    }

    /**
     * @param list<int> $statuses
     */
    #[DataProvider('requests')]
    public function testRequest(string $request, array $statuses, bool $open): void
    {
        $client = new Client(self::$port);
        $client->write($request);

        $received = [];
        foreach ($statuses as $_) {
            $received[] = self::statusCode($client->response()[0]);
        }
        self::assertSame($statuses, $received);

        if (!$open) {
            self::assertSame('', $client->readAll(), 'no further response');
            self::assertTrue($client->closed(), 'closed, not timed out');
            return;
        }

        [$head, $body] = $client->request(self::FOLLOW_UP);
        self::assertSame(200, self::statusCode($head));
        self::assertSame('follow', $body);
    }

    private static function statusCode(string $head): int
    {
        return preg_match('~^HTTP/1\.[01] (\d{3}) ~', $head, $matches) === 1 ? (int) $matches[1] : 0;
    }
}
