<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\PhpVcr;

use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\PhpVcr\PhpVcrCassetteImporter;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Tests\Support\DecodesJsonBody;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpVcrCassetteImporter::class)]
final class PhpVcrCassetteImporterTest extends TestCase
{
    use DecodesJsonBody;

    public function testImportLegacyVcrCassetteAndReplay(): void
    {
        $vcrFixturePath = __DIR__ . '/../../Fixtures/vcr/llm_chat_completion.yml';
        $this->assertFileExists($vcrFixturePath);

        $cassette = PhpVcrCassetteImporter::fromYaml($vcrFixturePath);
        $this->assertCount(1, $cassette);

        $store = new class($cassette) implements \CleatSquad\HttpReplay\Contract\CassetteStoreInterface {
            public function __construct(private Cassette $cassette) {}
            public function load(string $name): ?Cassette { return $name === 'llm_cassette' ? $this->cassette : null; }
            public function save(string $name, Cassette $cassette): void {}
        };

        $engine = new HttpReplayEngine(
            ExecutionMode::Replay,
            $store,
            'llm_cassette',
            new DefaultRequestMatcher(),
            new DefaultSanitizer()
        );

        $request = new Request(
            'POST',
            'https://api.openai.com/v1/chat/completions',
            ['Content-Type' => 'application/json'],
            '{"model":"gpt-5","prompt":"Tell me a joke"}'
        );

        $response = $engine->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $bodyJson = self::decodeJsonObject((string) $response->getBody());
        $this->assertSame('chatcmpl-mock123', $bodyJson['id']);
    }
}
