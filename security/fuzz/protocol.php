<?php

declare(strict_types=1);

use PhpFuzzer\Config;
use Semperton\Workerman\Internal\HttpProtocol;
use Semperton\Workerman\Internal\RequestConverter;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;

/**
 * Fuzzes the parsing of raw requests: HttpProtocol, Workerman's Http and the RequestConverter
 *
 * security/fuzz/vendor/bin/php-fuzzer fuzz security/fuzz/protocol.php <corpus> [--max-runs <num>]
 *
 * Any throwable other than the documented InvalidArgumentException of the converter is a finding,
 * PHP-Fuzzer also reports warnings and notices
 * A complete request must be complete only at its length: a prefix that is rejected or complete
 * would be parsed differently when the request arrives in parts (request smuggling)
 * The rest of the request after the prefix must give the same length, a chunked body is read on from where the prefix stopped
 * Complete requests must be decoded like by Workerman, chunked bodies are decoded by HttpProtocol
 *
 * @var Config $config
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const MAX_BODY_SIZE = 1024;

$converter = new RequestConverter();

$config->setMaxLen(4096);
$config->setTarget(static function (string $input) use ($converter): void {
    $full = connection();
    $part = connection();

    try {
        parse($input, $full, $part, $converter);
    } finally {
        // connections are registered in TcpConnection::$connections until they are destroyed
        $full->destroy();
        $part->destroy();
    }
});

function parse(string $input, TcpConnection $full, TcpConnection $part, RequestConverter $converter): void
{
    $length = HttpProtocol::input($input, $full);

    if ($length === 0 || $full->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
        return;
    }

    // a prefix at a position chosen by the input, it arrived first
    $cut = $length > 1 ? crc32($input) % ($length - 1) + 1 : 0;
    if ($cut > 0) {
        $partLength = HttpProtocol::input(substr($input, 0, $cut), $part);
        if ($partLength !== 0 && $partLength !== $length) {
            throw new LogicException("The prefix of $cut bytes has the length $partLength, the request $length");
        }
        if ($part->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            throw new LogicException("The prefix of $cut bytes was rejected, the request is valid");
        }
        // the rest arrives, a chunked body continues where the prefix stopped
        $resumedLength = HttpProtocol::input($input, $part);
        if ($resumedLength !== $length) {
            throw new LogicException("After a prefix of $cut bytes the request has the length $resumedLength, in one read $length");
        }
    }

    $request = HttpProtocol::decode(substr($input, 0, $length), $full);
    if (!$request instanceof Request) {
        throw new LogicException('Not a request');
    }

    // the same request as from Workerman, which accepts every request HttpProtocol accepts
    $workerman = connection();
    try {
        $workermanLength = Http::input($input, $workerman);
        if ($workermanLength !== $length) {
            throw new LogicException("Workerman's length is $workermanLength, HttpProtocol's $length");
        }
        $expected = Http::decode(substr($input, 0, $length), $workerman);
        if (!$expected instanceof Request || $expected->rawBuffer() !== $request->rawBuffer() || $expected->trailer() !== $request->trailer()) {
            throw new LogicException('Decoded differently than by Workerman');
        }
    } finally {
        $workerman->destroy();
    }

    try {
        $converter->convert($request, $full);
    } catch (InvalidArgumentException) {
        // an invalid host, header or target, answered with a 400
    }
}

function connection(): TcpConnection
{
    $connection = (new SocketConnection())->connection;
    $connection->maxPackageSize = MAX_BODY_SIZE + 16 * 1024;
    /** @psalm-suppress PossiblyNullPropertyAssignment */
    $connection->context->maxBodySize = MAX_BODY_SIZE;

    return $connection;
}
