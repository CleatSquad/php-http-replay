<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Integration\PhpVcr;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\PhpVcr\PhpVcrCassetteImporter;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpVcrCassetteImporter::class)]
final class PhpVcrRoundTripCompatibilityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_vcr_compat_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFullRoundTripFromVcrYamlToJsonStoreAndReplay(): void
    {
        // 1. Legacy PHP-VCR YAML cassette
        $vcrFixturePath = __DIR__ . '/../../Fixtures/vcr/agent_weather_tool.yml';
        $this->assertFileExists($vcrFixturePath);

        // 2. Import into native Cassette
        $importedCassette = PhpVcrCassetteImporter::fromYaml($vcrFixturePath);
        $this->assertCount(1, $importedCassette);

        $importedExchange = $importedCassette->get(0);

        // Preservation checks
        $this->assertSame('POST', $importedExchange->request()->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $importedExchange->request()->getUri());
        $this->assertSame('api.openai.com', $importedExchange->request()->getHeaderLine('Host'));
        $this->assertSame('application/json', $importedExchange->request()->getHeaderLine('Content-type'));
        $this->assertStringContainsString('Quel temps fait-il', (string) $importedExchange->request()->getBody());

        $this->assertSame(200, $importedExchange->response()->getStatusCode());
        $this->assertSame('application/json', $importedExchange->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('weather_tool', (string) $importedExchange->response()->getBody());

        // 3. Save to JsonCassetteStore
        $jsonStore = new JsonCassetteStore($this->tempDir);
        $jsonStore->save('converted_weather', $importedCassette);
        $this->assertTrue($jsonStore->exists('converted_weather'));

        // 4. Reload from JsonCassetteStore
        $reloadedCassette = $jsonStore->load('converted_weather');
        $this->assertNotNull($reloadedCassette);
        $this->assertCount(1, $reloadedCassette);

        // 5. Replay via HttpReplayEngine
        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $jsonStore,
            'converted_weather',
            new DefaultRequestMatcher(),
            new DefaultSanitizer()
        );

        $request = new Request(
            'POST',
            'https://api.openai.com/v1/chat/completions',
            [
                'Host' => 'api.openai.com',
                'Content-Type' => 'application/json',
            ],
            '{"model":"gpt-5","messages":[{"role":"user","content":"Quel temps fait-il \u00e0 Paris ?"}]}'
        );

        $response = $engine->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('chatcmpl-mocktool123', $body);
        $this->assertStringContainsString('weather_tool', $body);
    }

    public function testImportPreservesOriginalUnsanitizedDataSecurityBoundary(): void
    {
        $rawYaml = <<<'YAML'
-
    request:
        method: POST
        url: 'https://api.openai.com/v1/chat/completions'
        headers:
            Authorization: 'Bearer REAL_IMPORT_SECRET'
        body: '{"api_key":"REAL_BODY_SECRET"}'
    response:
        status: 200
        headers:
            Set-Cookie: 'SESS=secret123'
        body: '{"status":"ok"}'
    index: 0
YAML;

        $cassette = PhpVcrCassetteImporter::fromYaml($rawYaml);
        $ex = $cassette->get(0);

        // IMPORT != SANITIZATION invariant check
        $this->assertSame('Bearer REAL_IMPORT_SECRET', $ex->request()->getHeaderLine('Authorization'));
        $this->assertStringContainsString('REAL_BODY_SECRET', (string) $ex->request()->getBody());

        // Sanitization happens during persistence through Sanitizer + Store pipeline
        $sanitizer = new DefaultSanitizer();
        $sanitizedReq = $sanitizer->sanitizeRequest($ex->request());
        $sanitizedRes = $sanitizer->sanitizeResponse($ex->response());

        $this->assertSame('Bearer [REDACTED]', $sanitizedReq->getHeaderLine('Authorization'));
        $this->assertStringContainsString('[REDACTED]', (string) $sanitizedReq->getBody());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
