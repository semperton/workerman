<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use WeakMap;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

use function is_array;
use function is_string;
use function microtime;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function strtr;
use function trim;
use function urldecode;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 * Converts Workerman requests to PSR-7 server requests, as PHP-FPM would populate them
 * Forwarded headers (X-Forwarded-For, -Proto) are left to the application, only it knows which proxies to trust
 * Workerman requests may be cached and reused, so they are never modified
 *
 * Requests are built with nyholm/psr7 directly, PSR-17 factories would clone the request for every header
 */
final class RequestConverter
{
    /**
     * Workerman asks the os for the local address every time, it doesn't change during a connection
     *
     * @var WeakMap<TcpConnection, array{string, int}> ip and port
     */
    private WeakMap $localAddresses;

    private readonly Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
        /** @var WeakMap<TcpConnection, array{string, int}> */
        $localAddresses = new WeakMap();
        $this->localAddresses = $localAddresses;
    }

    public function convert(Request $request, TcpConnection $connection): ServerRequestInterface
    {
        $method = $request->method();
        $target = $request->uri();
        $protocol = $request->protocolVersion();
        [$localIp, $localPort] = $this->localAddress($connection);

        /** @var array<string, string> $headers */
        $headers = $request->header();

        if (isset($headers['host'])) {
            $host = $headers['host'];
        } elseif ($protocol === '1.0') {
            // http/1.0 may omit the host, the local address is used instead
            $host = $localIp . ':' . $localPort;
        } else {
            throw new InvalidArgumentException('Missing host');
        }

        // e.g. 'a#' or 'a?' would change the path of the uri
        if (
            preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/i', $host, $matches) !== 1
            || (int) ($matches[2] ?? 0) > 65535
        ) {
            throw new InvalidArgumentException("Invalid host < $host >");
        }

        // only the origin form (/path?query) is accepted, other targets would change the host of the uri
        if (!str_starts_with($target, '/')) {
            throw new InvalidArgumentException("Invalid request target < $target >");
        }

        // tls is terminated by the reverse proxy, which reports the scheme in X-Forwarded-Proto
        $uri = 'http://' . $host . $target;

        $time = microtime(true);
        $serverParams = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'QUERY_STRING' => $request->queryString(),
            'SERVER_PROTOCOL' => 'HTTP/' . $protocol,
            'SERVER_NAME' => $matches[1],
            // workerman keeps the brackets of ipv6 addresses
            'SERVER_ADDR' => trim($localIp, '[]'),
            'SERVER_PORT' => $localPort,
            'REMOTE_ADDR' => trim($connection->getRemoteIp(), '[]'),
            'REMOTE_PORT' => $connection->getRemotePort(),
            'REQUEST_TIME' => (int) $time,
            'REQUEST_TIME_FLOAT' => $time,
        ];

        // like nginx and apache, names with underscores are skipped, 'x_forwarded_for' would replace 'x-forwarded-for'
        // 'proxy' is skipped, as HTTP_PROXY configures the proxy of http clients (httpoxy)
        foreach ($headers as $name => $value) {
            if ($name !== 'proxy' && !str_contains($name, '_')) {
                $key = strtoupper(strtr($name, '-', '_'));
                $serverParams[$key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' ? $key : 'HTTP_' . $key] = $value;
            }
        }

        // validates the headers, parses the query and creates the body stream lazily
        $body = $request->rawBody();
        $serverRequest = new ServerRequest($method, $uri, $headers, $body, $protocol, $serverParams);

        if (isset($headers['cookie'])) {
            $serverRequest = $serverRequest->withCookieParams($this->cookies($request));
        }

        // like PHP-FPM, only form posts are parsed, other bodies are left to the application
        if ($body === '' || $method !== 'POST') {
            return $serverRequest;
        }

        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            /** @var array<string, mixed> $post */
            $post = $request->post();
            return $serverRequest->withParsedBody($post);
        }

        if (str_starts_with($contentType, 'multipart/form-data')) {
            /** @var array<string, mixed> $post */
            $post = $request->post();
            /** @var array<string, mixed> $files */
            $files = $request->file();
            return $serverRequest
                ->withParsedBody($post)
                ->withUploadedFiles($this->uploadedFiles($files));
        }

        return $serverRequest;
    }

    /**
     * @return array{string, int} ip and port
     */
    private function localAddress(TcpConnection $connection): array
    {
        $address = $this->localAddresses[$connection] ?? null;

        if ($address === null) {
            $address = [$connection->getLocalIp(), $connection->getLocalPort()];
            $this->localAddresses[$connection] = $address;
        }

        return $address;
    }

    /**
     * Cookie values are url decoded like in $_COOKIE
     *
     * @return array<string, string>
     */
    private function cookies(Request $request): array
    {
        /** @var array<string, string> $cookies */
        $cookies = $request->cookie();

        foreach ($cookies as $name => $value) {
            $cookies[$name] = urldecode($value);
        }

        return $cookies;
    }

    /**
     * Workerman nests one array per file under the field names, e.g. 'docs[]' becomes ['docs' => [0 => [...]]]
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function uploadedFiles(array $files): array
    {
        $uploadedFiles = [];

        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                continue;
            }

            $uploadedFiles[$key] = isset($file['tmp_name']) && is_string($file['tmp_name'])
                ? $this->uploadedFile($file)
                : $this->uploadedFiles($file);
        }

        return $uploadedFiles;
    }

    /**
     * Workerman removes the temporary file after the request, the opened stream keeps its contents readable
     *
     * @param array<array-key, mixed> $file
     */
    private function uploadedFile(array $file): UploadedFileInterface
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        $tmpName = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        $name = isset($file['name']) ? (string) $file['name'] : null;

        // browsers send empty file inputs without a name, PHP reports them as missing
        if ($error === UPLOAD_ERR_OK && $name === '' && $size === 0) {
            $error = UPLOAD_ERR_NO_FILE;
        }

        $stream = $error === UPLOAD_ERR_OK && $tmpName !== ''
            ? $this->factory->createStreamFromFile($tmpName)
            : $this->factory->createStream();

        return $this->factory->createUploadedFile(
            $stream,
            $size,
            $error,
            $name,
            isset($file['type']) ? (string) $file['type'] : null,
        );
    }
}
