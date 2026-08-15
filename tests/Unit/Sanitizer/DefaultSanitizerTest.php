<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Sanitizer;

use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Tests\Support\DecodesJsonBody;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultSanitizer::class)]
final class DefaultSanitizerTest extends TestCase
{
    use DecodesJsonBody;

    private DefaultSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new DefaultSanitizer();
    }

    public function testSanitizeRequestHeadersAndJsonBody(): void
    {
        $req = new Request(
            'POST',
            'https://api.example.com',
            [
                'Authorization' => 'Bearer REAL_SECRET_TOKEN',
                'x-api-key' => 'SECRET_API_KEY',
                'Content-Type' => 'application/json',
            ],
            '{"model":"gpt-4","api_key":"SECRET_IN_BODY","nested":{"token":"NESTED_TOKEN","other":"val"}}'
        );

        $sanitized = $this->sanitizer->sanitizeRequest($req);

        // Header sanitization
        $this->assertSame('Bearer [REDACTED]', $sanitized->getHeaderLine('Authorization'));
        $this->assertSame('[REDACTED]', $sanitized->getHeaderLine('x-api-key'));
        $this->assertSame('application/json', $sanitized->getHeaderLine('Content-Type'));

        // Body sanitization
        $json = self::decodeJsonObject((string) $sanitized->getBody());
        $nested = self::nestedObject($json, 'nested');
        $this->assertSame('gpt-4', $json['model']);
        $this->assertSame('[REDACTED]', $json['api_key']);
        $this->assertSame('[REDACTED]', $nested['token']);
        $this->assertSame('val', $nested['other']);

        // Invariant: original request remains unchanged
        $this->assertSame('Bearer REAL_SECRET_TOKEN', $req->getHeaderLine('Authorization'));
    }

    public function testSanitizeResponseHeadersAndJsonBody(): void
    {
        $res = new Response(
            200,
            ['Set-Cookie' => 'SESSION_ID=xyz123'],
            '{"status":"ok","secret":"RESPONSE_SECRET"}'
        );

        $sanitized = $this->sanitizer->sanitizeResponse($res);

        $this->assertSame('[REDACTED]', $sanitized->getHeaderLine('Set-Cookie'));
        $json = self::decodeJsonObject((string) $sanitized->getBody());
        $this->assertSame('[REDACTED]', $json['secret']);

        // Original response unchanged
        $this->assertSame('SESSION_ID=xyz123', $res->getHeaderLine('Set-Cookie'));
    }

    public function testSanitizeRequestUriQueryParams(): void
    {
        $uri = 'https://api.example.test/resource?api_key=REAL_SECRET&token=MY_TOKEN&secret=TOP_SECRET&access_token=ACC123&password=PASS&foo=bar&baz=123';
        $req = new Request('GET', $uri);

        $sanitized = $this->sanitizer->sanitizeRequest($req);

        // Sanity check original request is UNTOUCHED
        $this->assertSame($uri, (string) $req->getUri());

        // Check sanitized URI query
        $sanitizedUri = (string) $sanitized->getUri();
        $this->assertSame('https://api.example.test/resource?api_key=%5BREDACTED%5D&token=%5BREDACTED%5D&secret=%5BREDACTED%5D&access_token=%5BREDACTED%5D&password=%5BREDACTED%5D&foo=bar&baz=123', $sanitizedUri);
    }

    public function testSanitizeRequestUriQueryParamsPreservesDuplicatesOrderingAndEncoding(): void
    {
        $uri = 'https://api.example.test/v1?token=SECRET1&token=SECRET2&encoded_key=hello%20world&api_key=VAL%26123&empty_val=&flag';
        $req = new Request('GET', $uri);

        $sanitized = $this->sanitizer->sanitizeRequest($req);

        $this->assertSame($uri, (string) $req->getUri());
        $sanitizedUri = (string) $sanitized->getUri();

        $this->assertStringContainsString('token=%5BREDACTED%5D&token=%5BREDACTED%5D', $sanitizedUri);
        $this->assertStringContainsString('encoded_key=hello%20world', $sanitizedUri);
        $this->assertStringContainsString('api_key=%5BREDACTED%5D', $sanitizedUri);
        $this->assertStringContainsString('empty_val=', $sanitizedUri);
        $this->assertStringContainsString('flag', $sanitizedUri);
    }
}
