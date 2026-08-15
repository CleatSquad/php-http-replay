<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class LockConcurrencyTest extends TestCase
{
    private string $tempDir;
    private JsonCassetteStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/http_replay_lock_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->store = new JsonCassetteStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
    }

    public function testSaveAcquiresLockAndReleasesCleanly(): void
    {
        $exchange = new Exchange(new Request('GET', 'https://api.example.com/lock'), new Response(200, [], 'OK'));
        $cassette = new Cassette(2, [$exchange]);

        $this->store->save('lock_cassette', $cassette);

        $filePath = $this->tempDir . '/lock_cassette.json';
        $this::assertFileExists($filePath);
        $this::assertFileDoesNotExist($filePath . '.lock');
    }
}
