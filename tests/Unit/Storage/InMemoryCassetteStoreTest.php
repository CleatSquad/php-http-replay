<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Storage;

use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class InMemoryCassetteStoreTest extends TestCase
{
    public function testSaveAndLoadCassetteInMemory(): void
    {
        $store = new InMemoryCassetteStore();

        $this->assertFalse($store->exists('test_cassette'));
        $this->assertNull($store->load('test_cassette'));

        $request = new Request('GET', 'https://api.example.com/data');
        $response = new Response(200, [], '{"success":true}');
        $exchange = new Exchange($request, $response);
        $cassette = new Cassette(1, [$exchange], ['key' => 'val']);

        $store->save('test_cassette', $cassette);

        $this->assertTrue($store->exists('test_cassette'));

        $loaded = $store->load('test_cassette');
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->version());
        $this->assertCount(1, $loaded->exchanges());
        $this->assertSame(['key' => 'val'], $loaded->metadata());
    }

    public function testInitialCassettesConstructor(): void
    {
        $cassette = new Cassette(1, []);
        $store = new InMemoryCassetteStore(['preset' => $cassette]);

        $this->assertTrue($store->exists('preset'));
        $this->assertSame($cassette, $store->load('preset'));
    }
}
