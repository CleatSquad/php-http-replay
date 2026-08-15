<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Integration;

use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class NonSeekableStreamTest extends TestCase
{
    public function testSanitizerReplacesNonSeekableStreamWithSeekableStream(): void
    {
        $nonSeekableStream = new class('{"token":"secret_123"}') implements StreamInterface {
            /** @var resource */
            private $resource;

            public function __construct(string $content)
            {
                $res = fopen('php://temp', 'r+');
                if ($res === false) {
                    throw new \RuntimeException('Failed to open php://temp');
                }
                $this->resource = $res;
                fwrite($this->resource, $content);
                rewind($this->resource);
            }

            public function __toString(): string
            {
                return (string) stream_get_contents($this->resource);
            }

            public function close(): void { fclose($this->resource); }

            /** @return resource|null */
            public function detach()
            {
                $res = $this->resource;
                return is_resource($res) ? $res : null;
            }

            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool { return feof($this->resource); }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('Not seekable'); }
            public function rewind(): void { throw new \RuntimeException('Not seekable'); }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return true; }

            public function read(int $length): string
            {
                if ($length < 1) {
                    return '';
                }
                $res = fread($this->resource, $length);
                return $res !== false ? $res : '';
            }

            public function getContents(): string { return (string) stream_get_contents($this->resource); }
            public function getMetadata(?string $key = null) { return null; }
        };

        $request = new Request('POST', 'https://api.example.com', [], $nonSeekableStream);
        $this->assertFalse($request->getBody()->isSeekable());

        $sanitizer = new DefaultSanitizer();
        $sanitizedRequest = $sanitizer->sanitizeRequest($request);

        // Sanitized request stream must now be seekable and readable
        $this->assertTrue($sanitizedRequest->getBody()->isSeekable());
        $this->assertSame('{"token":"[REDACTED]"}', (string) $sanitizedRequest->getBody());
    }
}
