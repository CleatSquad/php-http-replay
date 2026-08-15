<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SchemaV2CassetteTest extends TestCase
{
    private string $tempDir;
    private JsonCassetteStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_v2_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->store = new JsonCassetteStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
    }

    public function testSaveWritesSchemaVersion2(): void
    {
        $exchange = new Exchange(new Request('GET', 'https://api.example.com/v2'), new Response(200, [], 'V2 Content'));
        $cassette = new Cassette(2, [$exchange]);

        $this->store->save('v2_cassette', $cassette);

        $filePath = $this->tempDir . '/v2_cassette.json';
        $this::assertFileExists($filePath);

        $rawJson = (string) file_get_contents($filePath);
        $data = json_decode($rawJson, true);

        $this::assertIsArray($data);
        $this::assertSame(2, $data['version']);
    }

    public function testSavingALegacyCassetteMigratesItToSchemaVersion2(): void
    {
        $exchange = new Exchange(new Request('GET', 'https://api.example.com/v1'), new Response(200, [], 'Legacy'));

        $this->store->save('migrated', new Cassette(1, [$exchange]));

        $loaded = $this->store->load('migrated');
        $this->assertNotNull($loaded);
        $this->assertSame(JsonCassetteStore::CURRENT_SCHEMA_VERSION, $loaded->version());
        $this->assertSame('Legacy', (string) $loaded->exchanges()[0]->response()->getBody());
    }

    public function testLoadsLegacyVersion1Cassette(): void
    {
        $v1Json = json_encode([
            'version' => 1,
            'metadata' => [],
            'exchanges' => [
                [
                    'request' => ['method' => 'GET', 'uri' => 'https://api.example.com/v1', 'headers' => [], 'body' => ''],
                    'response' => ['status' => 200, 'headers' => [], 'body' => 'Legacy V1'],
                ]
            ],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->tempDir . '/legacy_v1.json', $v1Json);

        $loaded = $this->store->load('legacy_v1');
        $this::assertNotNull($loaded);
        $this::assertSame(1, $loaded->version());
        $this::assertCount(1, $loaded->exchanges());
        $this::assertSame('Legacy V1', (string) $loaded->exchanges()[0]->response()->getBody());
    }
}
