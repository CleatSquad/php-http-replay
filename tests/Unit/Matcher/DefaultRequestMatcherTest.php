<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Matcher;

use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Exchange;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultRequestMatcher::class)]
final class DefaultRequestMatcherTest extends TestCase
{
    private DefaultRequestMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DefaultRequestMatcher();
    }

    public function testMethodMatchAndMismatch(): void
    {
        $recReq = new Request('GET', 'https://api.example.com/v1/resource');
        $exchange = new Exchange($recReq, new Response(200));

        $reqMatch = new Request('GET', 'https://api.example.com/v1/resource');
        $resMatch = $this->matcher->match($reqMatch, $exchange);
        $this->assertTrue($resMatch->matched());

        $reqMismatch = new Request('POST', 'https://api.example.com/v1/resource');
        $resMismatch = $this->matcher->match($reqMismatch, $exchange);
        $this->assertFalse($resMismatch->matched());
        $this->assertArrayHasKey('method', $resMismatch->differences());
    }

    public function testUriMatchAndMismatch(): void
    {
        $recReq = new Request('GET', 'https://api.example.com:8080/v1/resource?a=1');
        $exchange = new Exchange($recReq, new Response(200));

        $reqMatch = new Request('GET', 'https://api.example.com:8080/v1/resource?a=1');
        $this->assertTrue($this->matcher->match($reqMatch, $exchange)->matched());

        $reqDiffPath = new Request('GET', 'https://api.example.com:8080/v1/other');
        $resPath = $this->matcher->match($reqDiffPath, $exchange);
        $this->assertFalse($resPath->matched());
        $this->assertArrayHasKey('uri', $resPath->differences());

        $reqDiffScheme = new Request('GET', 'http://api.example.com:8080/v1/resource?a=1');
        $this->assertFalse($this->matcher->match($reqDiffScheme, $exchange)->matched());
    }

    public function testQueryParameterOrdering(): void
    {
        $recReq = new Request('GET', 'https://api.example.com/v1/resource?a=1&b=2');
        $exchange = new Exchange($recReq, new Response(200));

        $reqReordered = new Request('GET', 'https://api.example.com/v1/resource?b=2&a=1');
        $result = $this->matcher->match($reqReordered, $exchange);
        $this->assertTrue($result->matched());
    }

    public function testDuplicateQueryParameters(): void
    {
        $recReq = new Request('GET', 'https://api.example.com/v1/resource?tag=php&tag=vcr');
        $exchange = new Exchange($recReq, new Response(200));

        $reqSame = new Request('GET', 'https://api.example.com/v1/resource?tag=php&tag=vcr');
        $this->assertTrue($this->matcher->match($reqSame, $exchange)->matched());

        $reqDiff = new Request('GET', 'https://api.example.com/v1/resource?tag=php&tag=http');
        $result = $this->matcher->match($reqDiff, $exchange);
        $this->assertFalse($result->matched());
        $this->assertArrayHasKey('uri.query', $result->differences());
    }

    public function testHeaderCaseInsensitivityAndIgnoredHeaders(): void
    {
        $recReq = new Request(
            'GET',
            'https://api.example.com/v1/resource',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer SECRET1',
                'User-Agent' => 'Agent1',
            ]
        );
        $exchange = new Exchange($recReq, new Response(200));

        $req = new Request(
            'GET',
            'https://api.example.com/v1/resource',
            [
                'content-type' => 'application/json',
                'ACCEPT' => 'application/json',
                'Authorization' => 'Bearer DIFFERENT_SECRET', // Ignored header
                'User-Agent' => 'Agent2', // Ignored header
            ]
        );

        $result = $this->matcher->match($req, $exchange);
        $this->assertTrue($result->matched());
    }

    public function testMatchingContentTypeAndMismatch(): void
    {
        $recReq = new Request('GET', 'https://api.example.com/v1/resource', ['Content-Type' => 'application/json']);
        $exchange = new Exchange($recReq, new Response(200));

        $reqDiffHeader = new Request('GET', 'https://api.example.com/v1/resource', ['Content-Type' => 'text/html']);
        $result = $this->matcher->match($reqDiffHeader, $exchange);
        $this->assertFalse($result->matched());
        $this->assertArrayHasKey('header.content-type', $result->differences());
    }

    public function testJsonKeyOrdering(): void
    {
        $recReq = new Request('POST', 'https://api.example.com/v1/resource', [], '{"a":1,"b":2}');
        $exchange = new Exchange($recReq, new Response(200));

        $reqReordered = new Request('POST', 'https://api.example.com/v1/resource', [], '{"b":2,"a":1}');
        $result = $this->matcher->match($reqReordered, $exchange);
        $this->assertTrue($result->matched());
    }

    public function testNestedJsonMismatch(): void
    {
        $recReq = new Request('POST', 'https://api.example.com/v1/resource', [], '{"messages":[{"content":"hello"}]}');
        $exchange = new Exchange($recReq, new Response(200));

        $reqDiff = new Request('POST', 'https://api.example.com/v1/resource', [], '{"messages":[{"content":"bye"}]}');
        $result = $this->matcher->match($reqDiff, $exchange);
        $this->assertFalse($result->matched());
        $this->assertArrayHasKey('body.messages.0.content', $result->differences());
    }

    public function testArrayOrderingMismatch(): void
    {
        $recReq = new Request('POST', 'https://api.example.com/v1/resource', [], '["a","b"]');
        $exchange = new Exchange($recReq, new Response(200));

        $reqReordered = new Request('POST', 'https://api.example.com/v1/resource', [], '["b","a"]');
        $result = $this->matcher->match($reqReordered, $exchange);
        $this->assertFalse($result->matched());
        $this->assertArrayHasKey('body.0', $result->differences());
        $this->assertArrayHasKey('body.1', $result->differences());
    }

    public function testScalarTypeMismatch(): void
    {
        $recReq = new Request('POST', 'https://api.example.com/v1/resource', [], '{"count": 1}');
        $exchange = new Exchange($recReq, new Response(200));

        $reqTypeDiff = new Request('POST', 'https://api.example.com/v1/resource', [], '{"count": "1"}');
        $result = $this->matcher->match($reqTypeDiff, $exchange);
        $this->assertFalse($result->matched());
        $this->assertArrayHasKey('body.count', $result->differences());
    }

    public function testInvalidJsonAndEmptyBodies(): void
    {
        // Invalid JSON fallback to string/byte comparison
        $recReq = new Request('POST', 'https://api.example.com/v1/resource', [], 'not valid json');
        $exchange = new Exchange($recReq, new Response(200));

        $reqSame = new Request('POST', 'https://api.example.com/v1/resource', [], 'not valid json');
        $this->assertTrue($this->matcher->match($reqSame, $exchange)->matched());

        $reqDiff = new Request('POST', 'https://api.example.com/v1/resource', [], 'other string');
        $resDiff = $this->matcher->match($reqDiff, $exchange);
        $this->assertFalse($resDiff->matched());
        $this->assertArrayHasKey('body', $resDiff->differences());

        // Empty bodies
        $recEmpty = new Request('POST', 'https://api.example.com/v1/resource', [], '');
        $exchangeEmpty = new Exchange($recEmpty, new Response(200));

        $reqEmpty = new Request('POST', 'https://api.example.com/v1/resource', [], '');
        $this->assertTrue($this->matcher->match($reqEmpty, $exchangeEmpty)->matched());
    }
}
