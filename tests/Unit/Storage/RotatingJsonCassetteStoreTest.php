<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\RotatingJsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RotatingJsonCassetteStore::class)]
final class RotatingJsonCassetteStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_rotating_tests_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testRotationAndFileRetentionLimitsAcB1(): void
    {
        // 2 exchanges per file, max 2 files retained
        $store = new RotatingJsonCassetteStore(
            baseDir: $this->tempDir,
            maxExchangesPerFile: 2,
            maxFiles: 2,
        );

        $createExchange = static fn (int $id): Exchange => new Exchange(
            new Request('POST', 'https://api.example.com/v1/chat', [], (string) json_encode(['msg' => 'msg-' . $id])),
            new Response(200, [], (string) json_encode(['reply' => 'reply-' . $id])),
            1700000000 + $id
        );

        // Save 1 exchange -> 1 file
        $store->save('turn_llm', new Cassette(2, [$createExchange(1)]));
        $files1 = glob($this->tempDir . '/turn_llm*.json') ?: [];
        $this->assertCount(1, $files1);

        // Save 2 exchanges -> still 1 file
        $store->save('turn_llm', new Cassette(2, [$createExchange(1), $createExchange(2)]));
        $files2 = glob($this->tempDir . '/turn_llm*.json') ?: [];
        $this->assertCount(1, $files2);

        // Save 3 exchanges -> rotates to 2 files: turn_llm-0001 (2 ex) + turn_llm-0002 (1 ex)
        $store->save('turn_llm', new Cassette(2, [$createExchange(1), $createExchange(2), $createExchange(3)]));
        $files3 = glob($this->tempDir . '/turn_llm*.json') ?: [];
        $this->assertCount(2, $files3);

        // Save 5 exchanges -> would be 3 chunks: [1,2], [3,4], [5]. Max files is 2, so oldest file is pruned!
        $store->save('turn_llm', new Cassette(2, [
            $createExchange(1),
            $createExchange(2),
            $createExchange(3),
            $createExchange(4),
            $createExchange(5),
        ]));

        $files4 = glob($this->tempDir . '/turn_llm*.json') ?: [];
        // AC-B1: Exactly maxFiles (2) files remain present on disk
        $this->assertCount(2, $files4, 'The number of retained cassette files must not exceed maxFiles');

        $fileNames = array_map('basename', $files4);
        sort($fileNames);
        $this->assertSame(['turn_llm-0002.json', 'turn_llm-0003.json'], $fileNames);
    }

    public function testMultiFileResolutionAllowsReplayingExchangesAcrossRotatedFilesAcB2(): void
    {
        $store = new RotatingJsonCassetteStore(
            baseDir: $this->tempDir,
            maxExchangesPerFile: 2,
            maxFiles: 5,
        );

        $createExchange = static fn (int $id): Exchange => new Exchange(
            new Request('POST', 'https://api.example.com/v1/chat', [], (string) json_encode(['id' => $id])),
            new Response(200, [], (string) json_encode(['reply' => 'reply-' . $id])),
            1700000000 + $id
        );

        // Save 4 exchanges -> partitioned across 2 files
        $store->save('turn_llm', new Cassette(2, [
            $createExchange(1),
            $createExchange(2),
            $createExchange(3),
            $createExchange(4),
        ]));

        $loaded = $store->load('turn_llm');
        $this->assertNotNull($loaded);
        // AC-B2: all 4 exchanges are loaded seamlessly
        $this->assertCount(4, $loaded);
        $this->assertSame(1700000001, $loaded->get(0)->recordedAt());
        $this->assertSame(1700000004, $loaded->get(3)->recordedAt());
    }

    public function testLoadNonExistentReturnsNull(): void
    {
        $store = new RotatingJsonCassetteStore($this->tempDir);
        $this->assertNull($store->load('absent'));
        $this->assertFalse($store->exists('absent'));
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
