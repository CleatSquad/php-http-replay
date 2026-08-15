<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Sanitizer;

use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class JsonPathSanitizerTest extends TestCase
{
    public function testSanitizeNestedJsonPathsInRequest(): void
    {
        $sanitizer = new DefaultSanitizer(
            sensitiveHeaders: [],
            sensitiveBodyKeys: [],
            sensitiveQueryParams: [],
            sensitiveJsonPaths: ['$.user.profile.token', 'user.credit_card.number', '$.items.secret'],
            replacement: '[REDACTED]'
        );

        $body = [
            'user' => [
                'name' => 'John',
                'profile' => [
                    'email' => 'john@example.com',
                    'token' => 'super_secret_token_123',
                ],
                'credit_card' => [
                    'number' => '4111222233334444',
                    'exp' => '12/28',
                ],
            ],
            'items' => [
                ['id' => 1, 'secret' => 'sec_1'],
                ['id' => 2, 'secret' => 'sec_2'],
            ],
        ];

        $request = new Request('POST', 'https://api.example.com', [], (string) json_encode($body));
        $sanitized = $sanitizer->sanitizeRequest($request);

        /** @var array{user: array{name: string, profile: array{email: string, token: string}, credit_card: array{number: string, exp: string}}, items: list<array{id: int, secret: string}>} $sanitizedData */
        $sanitizedData = json_decode((string) $sanitized->getBody(), true);

        $this->assertSame('John', $sanitizedData['user']['name']);
        $this->assertSame('john@example.com', $sanitizedData['user']['profile']['email']);
        $this->assertSame('[REDACTED]', $sanitizedData['user']['profile']['token']);
        $this->assertSame('[REDACTED]', $sanitizedData['user']['credit_card']['number']);
        $this->assertSame('12/28', $sanitizedData['user']['credit_card']['exp']);

        $this->assertSame('[REDACTED]', $sanitizedData['items'][0]['secret']);
        $this->assertSame('[REDACTED]', $sanitizedData['items'][1]['secret']);
    }

    public function testSanitizeNestedJsonPathsInResponse(): void
    {
        $sanitizer = new DefaultSanitizer(
            sensitiveHeaders: [],
            sensitiveBodyKeys: [],
            sensitiveQueryParams: [],
            sensitiveJsonPaths: ['$.data.auth.refresh_token'],
            replacement: '[REDACTED]'
        );

        $body = [
            'data' => [
                'auth' => [
                    'refresh_token' => 'refresh_xyz',
                ],
            ],
        ];

        $response = new Response(200, [], (string) json_encode($body));
        $sanitized = $sanitizer->sanitizeResponse($response);

        /** @var array{data: array{auth: array{refresh_token: string}}} $sanitizedData */
        $sanitizedData = json_decode((string) $sanitized->getBody(), true);

        $this->assertSame('[REDACTED]', $sanitizedData['data']['auth']['refresh_token']);
    }

    public function testJsonPathKeepsKeysStartingWithSpecialCharacters(): void
    {
        $sanitizer = new DefaultSanitizer(
            sensitiveHeaders: [],
            sensitiveBodyKeys: [],
            sensitiveQueryParams: [],
            sensitiveJsonPaths: ['$.$ref.token'],
            replacement: '[REDACTED]'
        );

        $body = ['$ref' => ['token' => 'secret_value', 'id' => 7]];

        $request = new Request('POST', 'https://api.example.com', [], (string) json_encode($body));

        /** @var array{'$ref': array{token: string, id: int}} $sanitizedData */
        $sanitizedData = json_decode((string) $sanitizer->sanitizeRequest($request)->getBody(), true);

        $this->assertSame('[REDACTED]', $sanitizedData['$ref']['token']);
        $this->assertSame(7, $sanitizedData['$ref']['id']);
    }

    public function testConstructorRemainsCompatibleWithPositionalArguments(): void
    {
        $sanitizer = new DefaultSanitizer(
            ['authorization'],
            ['token'],
            ['api_key'],
            '[MASKED]'
        );

        $body = ['token' => 'secret_value'];

        $request = new Request('POST', 'https://api.example.com', [], (string) json_encode($body));

        /** @var array{token: string} $sanitizedData */
        $sanitizedData = json_decode((string) $sanitizer->sanitizeRequest($request)->getBody(), true);

        $this->assertSame('[MASKED]', $sanitizedData['token']);
    }
}
