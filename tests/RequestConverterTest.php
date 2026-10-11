<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Semperton\Workerman\RequestConverter;
use Semperton\Workerman\Tests\Fixtures\SocketConnection;
use Workerman\Protocols\Http\Request;

use function strlen;

final class RequestConverterTest extends TestCase
{
    public function testRequest(): void
    {
        $request = $this->convert(
            "GET /users/1?page=2&tags[]=a HTTP/1.1\r\n"
            . "Host: example.com:8080\r\n"
            . "Accept: application/json\r\n"
            . "Cookie: session=a%20b; theme=dark\r\n"
            . "X_Forwarded_For: 10.0.0.1\r\n"
            . "Proxy: evil.com\r\n\r\n",
        );
        $serverParams = $request->getServerParams();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('http://example.com:8080/users/1?page=2&tags%5B%5D=a', (string) $request->getUri());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(['page' => '2', 'tags' => ['a']], $request->getQueryParams());
        self::assertSame(['session' => 'a b', 'theme' => 'dark'], $request->getCookieParams());
        self::assertSame('127.0.0.1', $serverParams['REMOTE_ADDR']);
        self::assertSame('/users/1?page=2&tags[]=a', $serverParams['REQUEST_URI']);
        self::assertSame('example.com', $serverParams['SERVER_NAME']);
        self::assertSame('example.com:8080', $serverParams['HTTP_HOST']);
        self::assertSame('application/json', $serverParams['HTTP_ACCEPT']);
        self::assertArrayNotHasKey('HTTP_X_FORWARDED_FOR', $serverParams);
        self::assertArrayNotHasKey('HTTP_PROXY', $serverParams);
        self::assertNull($request->getParsedBody());
    }

    public function testIpv6(): void
    {
        $connection = (new SocketConnection('[::1]:50000'))->connection;

        $request = (new RequestConverter())->convert(new Request("GET / HTTP/1.1\r\nHost: [::1]:8080\r\n\r\n"), $connection);

        self::assertSame('http://[::1]:8080/', (string) $request->getUri());
        self::assertSame('[::1]', $request->getServerParams()['SERVER_NAME']);
        // like PHP-FPM, without brackets
        self::assertSame('::1', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testJsonBody(): void
    {
        $body = '{"name":"Ann"}';
        $request = $this->convert(
            "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body,
        );

        self::assertSame($body, (string) $request->getBody());
        self::assertSame('application/json', $request->getServerParams()['CONTENT_TYPE']);
        self::assertSame((string) strlen($body), $request->getServerParams()['CONTENT_LENGTH']);
        // left to the application, like with PHP-FPM
        self::assertNull($request->getParsedBody());
    }

    public function testFormBody(): void
    {
        $body = 'name=Ann&tags%5B%5D=a';
        $request = $this->convert(
            "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body,
        );

        self::assertSame(['name' => 'Ann', 'tags' => ['a']], $request->getParsedBody());
    }

    public function testUploadedFiles(): void
    {
        $body = "--boundary\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\n\r\n"
            . "Report\r\n"
            . "--boundary\r\n"
            . "Content-Disposition: form-data; name=\"docs[]\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "hello\r\n"
            . "--boundary\r\n"
            . "Content-Disposition: form-data; name=\"docs[]\"; filename=\"b.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "world\r\n"
            . "--boundary\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . "\r\n"
            . "--boundary--\r\n";

        $request = $this->convert(
            "POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=boundary\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body,
        );

        self::assertSame(['title' => 'Report'], $request->getParsedBody());

        $files = $request->getUploadedFiles();
        self::assertIsArray($files['docs']);

        $file = $this->uploadedFile($files['docs'][0]);
        self::assertSame('a.txt', $file->getClientFilename());
        self::assertSame('text/plain', $file->getClientMediaType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('hello', (string) $file->getStream());

        self::assertSame('world', (string) $this->uploadedFile($files['docs'][1])->getStream());

        // an empty file input, like in PHP
        self::assertSame(UPLOAD_ERR_NO_FILE, $this->uploadedFile($files['avatar'])->getError());
    }

    public function testMissingHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->convert("GET /hello HTTP/1.1\r\n\r\n");
    }

    #[TestWith(['a#'])]
    #[TestWith(['a?x'])]
    #[TestWith(['a@b'])]
    #[TestWith(['a:65536'])]
    #[TestWith(['a:99999'])]
    #[TestWith(['a,b'])]
    public function testInvalidHost(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->convert("GET /hello HTTP/1.1\r\nHost: $host\r\n\r\n");
    }

    #[TestWith(['evil.com/hello'])]
    #[TestWith(['http://evil.com/hello'])]
    public function testInvalidTarget(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->convert("GET $target HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private function uploadedFile(mixed $file): UploadedFileInterface
    {
        self::assertInstanceOf(UploadedFileInterface::class, $file);

        return $file;
    }

    private function convert(string $buffer): ServerRequestInterface
    {
        return (new RequestConverter())->convert(new Request($buffer), (new SocketConnection())->connection);
    }
}
