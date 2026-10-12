<?php

declare(strict_types=1);

/**
 * Hostile and edge case requests, see RawRequestTest
 * Every finding of a security test gets a case here
 *
 * name => [raw request, statuses of the responses, whether the connection stays open]
 * The server limits bodies to 1024 bytes, see Fixtures/server.php
 *
 * @return array<string, array{string, list<int>, bool}>
 */

$host = "Host: localhost\r\n";
$post = "POST /echo HTTP/1.1\r\n$host";
$get = "GET / HTTP/1.1\r\n$host";

return [
    // request smuggling, conflicting or invalid message lengths, rfc 9112 6.1 and 6.3
    'content-length and transfer-encoding' => ["{$post}Content-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding and content-length' => ["{$post}Transfer-Encoding: chunked\r\nContent-Length: 4\r\n\r\n0\r\n\r\n", [400], false],
    'content-length twice, different' => ["{$post}Content-Length: 4\r\nContent-Length: 5\r\n\r\nping!", [400], false],
    'content-length twice, same' => ["{$post}Content-Length: 4\r\nContent-Length: 4\r\n\r\nping", [400], false],
    'content-length list' => ["{$post}Content-Length: 4, 4\r\n\r\nping", [400], false],
    'content-length with sign' => ["{$post}Content-Length: +4\r\n\r\nping", [400], false],
    'content-length negative' => ["{$post}Content-Length: -1\r\n\r\n", [400], false],
    'content-length hex' => ["{$post}Content-Length: 0x4\r\n\r\nping", [400], false],
    'content-length with junk' => ["{$post}Content-Length: 4a\r\n\r\nping", [400], false],
    'content-length overflow' => ["{$post}Content-Length: 99999999999999999999999\r\n\r\nping", [413], false],
    'content-length leading zeros' => ["{$post}Content-Length: 0004\r\n\r\nping", [200], true],
    'transfer-encoding not chunked' => ["{$post}Transfer-Encoding: gzip\r\n\r\nping", [400], false],
    'transfer-encoding chunked not last' => ["{$post}Transfer-Encoding: chunked, gzip\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding gzip, chunked' => ["{$post}Transfer-Encoding: gzip, chunked\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding twice' => ["{$post}Transfer-Encoding: chunked\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding uppercase' => ["{$post}Transfer-Encoding: CHUNKED\r\n\r\n4\r\nping\r\n0\r\n\r\n", [200], true],
    'transfer-encoding xchunked' => ["{$post}Transfer-Encoding: xchunked\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding vertical tab' => ["{$post}Transfer-Encoding: \x0bchunked\r\n\r\n0\r\n\r\n", [400], false],
    'transfer-encoding in http/1.0' => ["POST /echo HTTP/1.0\r\n{$host}Transfer-Encoding: chunked\r\n\r\n4\r\nping\r\n0\r\n\r\n", [200], false],

    // chunked bodies, rfc 9112 7.1
    'chunk extension' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;a=b\r\nping\r\n0\r\n\r\n", [200], true],
    'chunk size invalid' => ["{$post}Transfer-Encoding: chunked\r\n\r\nzz\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk size with 0x' => ["{$post}Transfer-Encoding: chunked\r\n\r\n0x4\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk size with space' => ["{$post}Transfer-Encoding: chunked\r\n\r\n 4\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk size overflow' => ["{$post}Transfer-Encoding: chunked\r\n\r\nFFFFFFFFFFFFFFFF1\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk longer than its size' => ["{$post}Transfer-Encoding: chunked\r\n\r\n2\r\npingXX\r\n0\r\n\r\n", [400], false],
    'chunk with bare lf' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4\nping\n0\n\n", [400], false],
    // chunk extensions and trailers read differently by a proxy, e.g. a bare LF as line end ("funky chunks")
    'chunk extension without a name' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk extension with lf' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;\nping\r\n0\r\n\r\n", [400], false],
    'chunk extension with cr' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;a\rX\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk extension with nul' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;\0a\r\nping\r\n0\r\n\r\n", [400], false],
    'chunk extension quoted' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4;a=\"b c\"\r\nping\r\n0\r\n\r\n", [200], true],
    'last chunk with bare lf' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4\r\nping\r\n0\r\n\n", [400], false],
    'trailer with bare lf' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4\r\nping\r\n0\r\nX-A: a\nb\r\n\r\n", [400], false],
    'trailer with content-length' => ["{$post}Transfer-Encoding: chunked\r\n\r\n4\r\nping\r\n0\r\nContent-Length: 50\r\n\r\n", [200], true],

    // bodies the proxy may not expect, 0.CL desync
    'get with body' => ["{$get}Content-Length: 5\r\n\r\nhello", [200], true],
    'get with chunked body' => ["{$get}Transfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n", [200], true],

    // expect, rfc 9110 10.1.1
    'expect with the body' => ["{$post}Expect: 100-continue\r\nContent-Length: 4\r\n\r\nping", [200], true],
    'expect without a body' => ["{$get}Expect: 100-continue\r\n\r\n", [200], true],
    'expect invalid' => ["{$post}Expect: 100-continuex\r\nContent-Length: 4\r\n\r\nping", [200], true],
    'expect in http/1.0' => ["POST /echo HTTP/1.0\r\n{$host}Connection: keep-alive\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\nping", [200], true],
    'expect too large' => ["{$post}Expect: 100-continue\r\nContent-Length: 1025\r\n\r\n", [413], false],

    // header fields, rfc 9112 2.2 and 5
    'bare lf' => ["GET / HTTP/1.1\n{$host}\n", [408], false],
    'bare cr' => ["{$get}X-A: a\rb\r\n\r\n", [400], false],
    // a lf right before the crlf, a proxy that reads it as the line end sees an empty line, the end of the head
    'lf at the end of the request line' => ["GET / HTTP/1.1\n\r\n{$host}\r\n", [400], false],
    'lf at the end of a header' => ["{$get}X-A: a\n\r\nContent-Length: 4\r\n\r\nping", [400], false],
    'lf at the end of the host' => ["GET / HTTP/1.1\r\nHost: localhost\n\r\n\r\n", [400], false],
    'obs-fold' => ["{$get}X-A: a\r\n b\r\n\r\n", [400], false],
    'obs-fold transfer-encoding' => ["{$post}Transfer-Encoding:\r\n chunked\r\n\r\n0\r\n\r\n", [400], false],
    'space before colon' => ["GET / HTTP/1.1\r\nHost : localhost\r\n\r\n", [400], false],
    'transfer-encoding space before colon' => ["{$post}Transfer-Encoding : chunked\r\n\r\n0\r\n\r\n", [400], false],
    'numeric header name' => ["{$get}123: a\r\n\r\n", [200], true],
    'empty header name' => ["{$get}: a\r\n\r\n", [400], false],
    'nul in header' => ["{$get}X-A: a\0b\r\n\r\n", [400], false],
    'header too large' => ["{$get}X-A: " . str_repeat('a', 20 * 1024) . "\r\n\r\n", [431], false],

    // host, rfc 9112 3.2
    'missing host' => ["GET / HTTP/1.1\r\n\r\n", [400], false],
    'host twice' => ["{$get}{$host}\r\n", [400], false],
    'host with path' => ["GET / HTTP/1.1\r\nHost: evil.com/x\r\n\r\n", [400], false],
    'host with userinfo' => ["GET / HTTP/1.1\r\nHost: a@evil.com\r\n\r\n", [400], false],
    'host empty' => ["GET / HTTP/1.1\r\nHost: \r\n\r\n", [400], false],

    // request line, rfc 9112 3
    'absolute form' => ["GET http://evil.com/ HTTP/1.1\r\n{$host}\r\n", [400], false],
    'asterisk form' => ["OPTIONS * HTTP/1.1\r\n{$host}\r\n", [400], false],
    'authority form' => ["CONNECT evil.com:443 HTTP/1.1\r\n{$host}\r\n", [400], false],
    'target with fragment' => ["GET /a#b HTTP/1.1\r\n{$host}\r\n", [400], false],
    'target with nul' => ["GET /a\0b HTTP/1.1\r\n{$host}\r\n", [400], false],
    'target with utf-8' => ["GET /caf\xc3\xa9 HTTP/1.1\r\n{$host}\r\n", [400], false],
    'target with overlong utf-8' => ["GET /\xc0\xae\xc0\xae/ HTTP/1.1\r\n{$host}\r\n", [400], false],
    'target with characters browsers send raw' => ["GET /a|b?c[]=d^e\\f HTTP/1.1\r\n{$host}\r\n", [200], true],
    'target with space' => ["GET /a b HTTP/1.1\r\n{$host}\r\n", [400], false],
    'http/2.0' => ["GET / HTTP/2.0\r\n{$host}\r\n", [400], false],
    'http/1.2, answered as 1.1' => ["GET / HTTP/1.2\r\n{$host}\r\n", [200], true],
    'version lowercase' => ["GET / http/1.1\r\n{$host}\r\n", [400], false],
    'method lowercase' => ["get / HTTP/1.1\r\n{$host}\r\n", [400], false],
    'method unknown' => ["QUERY / HTTP/1.1\r\n{$host}\r\n", [400], false],
    'two spaces' => ["GET  / HTTP/1.1\r\n{$host}\r\n", [400], false],
    'leading empty line' => ["\r\n{$get}\r\n", [400], false],

    // connection handling and limits
    'pipelining' => [str_repeat("{$get}\r\n", 3), [200, 200, 200], true],
    'pipelining after a body' => ["{$post}Content-Length: 4\r\n\r\nping{$get}\r\n", [200, 200], true],
    'connection close' => ["{$get}Connection: close\r\n\r\n", [200], false],
    'http/1.0 closes' => ["GET / HTTP/1.0\r\n{$host}\r\n", [200], false],
    'http/1.0 without host' => ["GET / HTTP/1.0\r\n\r\n", [200], false],
    'body too large' => ["{$post}Content-Length: 1025\r\n\r\n" . str_repeat('a', 1025), [413], false],
    'chunked body too large' => ["{$post}Transfer-Encoding: chunked\r\n\r\n" . str_repeat("100\r\n" . str_repeat('a', 256) . "\r\n", 5) . "0\r\n\r\n", [413], false],
    // the limit is checked before the chunk is complete
    'chunk larger than the limit' => ["{$post}Transfer-Encoding: chunked\r\n\r\nFFFFFFFF\r\n" . str_repeat('a', 2048), [413], false],
];
