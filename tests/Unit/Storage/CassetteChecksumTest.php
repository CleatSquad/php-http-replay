<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Exception\InvalidCassetteException;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CassetteChecksumTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_checksum_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testChecksumIsAutomaticallyCalculatedAndVerified(): void
    {
        $store = new JsonCassetteStore($this->tempDir);
        $request = new Request('GET', 'https://api.example.com/test');
        $response = new Response(200, [], '{"status":"ok"}');
        $exchange = new Exchange($request, $response);
        $cassette = new Cassette(1, [$exchange]);

        $store->save('checksum_test', $cassette);

        // Load must succeed and verify checksum
        $loaded = $store->load('checksum_test');
        $this->assertNotNull($loaded);

        $checksum = $loaded->metadata()['checksum'] ?? null;
        $this->assertIsString($checksum);
        $this->assertStringStartsWith('sha256:', $checksum);
    }

    public function testCorruptedCassetteFailsChecksumVerification(): void
    {
        $store = new JsonCassetteStore($this->tempDir);
        $request = new Request('GET', 'https://api.example.com/test');
        $response = new Response(200, [], '{"status":"ok"}');
        $exchange = new Exchange($request, $response);
        $cassette = new Cassette(1, [$exchange]);

        $store->save('tamper_test', $cassette);

        // Tamper with the cassette file on disk manually
        $filePath = $this->tempDir . '/tamper_test.json';
        $content = (string) file_get_contents($filePath);
        $tamperedContent = str_replace('https://api.example.com/test', 'https://api.example.com/tampered', $content);
        file_put_contents($filePath, $tamperedContent);

        $this->expectException(InvalidCassetteException::class);
        $this->expectExceptionMessage('Cassette checksum mismatch. File content does not match its recorded checksum.');

        $store->load('tamper_test');
    }

    public function testChecksumIsRecomputedWhenExchangesAreAppended(): void
    {
        $store = new JsonCassetteStore($this->tempDir);
        $first = new Exchange(
            new Request('GET', 'https://api.example.com/first'),
            new Response(200, [], '{"n":1}')
        );

        $store->save('append_test', new Cassette(1, [$first]));

        $loaded = $store->load('append_test');
        $this->assertNotNull($loaded);

        // Appending an exchange is what Record and RecordOnce modes do: the stale
        // checksum carried in the metadata must not survive the second save.
        $second = new Exchange(
            new Request('GET', 'https://api.example.com/second'),
            new Response(200, [], '{"n":2}')
        );
        $store->save('append_test', new Cassette(
            $loaded->version(),
            [...$loaded->exchanges(), $second],
            $loaded->metadata()
        ));

        $reloaded = $store->load('append_test');
        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->exchanges());
    }
}
