<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Exception\InvalidCassetteException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonCassetteStore::class)]
final class JsonCassetteStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_tests_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testSaveAndLoadCassetteAtomicallyWithUnicodeAndMultipleExchanges(): void
    {
        $store = new JsonCassetteStore($this->tempDir);

        $req1 = new Request('GET', 'https://api.example.com/v1/user?q=مرحبا');
        $res1 = new Response(200, ['Content-Type' => 'application/json'], '{"name":"العرب"}');

        $req2 = new Request('POST', 'https://api.example.com/v1/user', [], '{"tag":"test"}');
        $res2 = new Response(201, [], '{"id":123}');

        $cassette = new Cassette(1, [
            new Exchange($req1, $res1),
            new Exchange($req2, $res2),
        ], ['created_by' => 'unit-test']);

        $this->assertFalse($store->exists('user_cassette'));
        $store->save('user_cassette', $cassette);
        $this->assertTrue($store->exists('user_cassette'));

        $loaded = $store->load('user_cassette');
        $this->assertNotNull($loaded);
        // The store stamps the schema it writes, so a version 1 cassette is migrated.
        $this->assertSame(JsonCassetteStore::CURRENT_SCHEMA_VERSION, $loaded->version());
        $this->assertCount(2, $loaded);
        $this->assertSame('unit-test', $loaded->metadata()['created_by']);

        // Exchange 0 assertions
        $ex0 = $loaded->get(0);
        $this->assertSame('GET', $ex0->request()->getMethod());
        $this->assertSame('https://api.example.com/v1/user?q=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7', (string) $ex0->request()->getUri());
        $this->assertSame('{"name":"العرب"}', (string) $ex0->response()->getBody());

        // Exchange 1 assertions
        $ex1 = $loaded->get(1);
        $this->assertSame('POST', $ex1->request()->getMethod());
        $this->assertSame(201, $ex1->response()->getStatusCode());
    }

    public function testLoadNonExistentCassetteReturnsNull(): void
    {
        $store = new JsonCassetteStore($this->tempDir);
        $this->assertNull($store->load('non_existent'));
    }

    public function testLoadMalformedJsonThrowsException(): void
    {
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/bad.json', 'invalid json content');

        $store = new JsonCassetteStore($this->tempDir);

        $this->expectException(InvalidCassetteException::class);
        $this->expectExceptionMessage('Invalid JSON syntax');
        $store->load('bad');
    }

    public function testLoadUnsupportedVersionThrowsException(): void
    {
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/version99.json', '{"version": 99, "exchanges": []}');

        $store = new JsonCassetteStore($this->tempDir);

        $this->expectException(InvalidCassetteException::class);
        $this->expectExceptionMessage('Unsupported cassette schema version 99');
        $store->load('version99');
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
