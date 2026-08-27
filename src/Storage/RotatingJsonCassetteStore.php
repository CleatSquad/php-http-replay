<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Storage;

use CleatSquad\HttpReplay\Contract\CassetteStoreInterface;
use CleatSquad\HttpReplay\Model\Cassette;
use CleatSquad\HttpReplay\Model\Exchange;
use Override;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class RotatingJsonCassetteStore implements CassetteStoreInterface
{
    private JsonCassetteStore $innerStore;

    public function __construct(
        private string $baseDir,
        private int $maxExchangesPerFile = 100,
        private int $maxFiles = 5,
        ?RequestFactoryInterface $requestFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->innerStore = new JsonCassetteStore(
            $this->baseDir,
            $requestFactory,
            $responseFactory,
            $streamFactory
        );
    }

    public function exists(string $name): bool
    {
        return $this->getRotatedFiles($name) !== [];
    }

    #[Override]
    public function load(string $name): ?Cassette
    {
        $files = $this->getRotatedFiles($name);
        if ($files === []) {
            return null;
        }

        /** @var list<Exchange> $allExchanges */
        $allExchanges = [];
        $latestMetadata = [];
        $version = JsonCassetteStore::CURRENT_SCHEMA_VERSION;

        foreach ($files as $file) {
            $cassette = $this->innerStore->load($file);
            if ($cassette !== null) {
                $version = $cassette->version();
                $latestMetadata = array_merge($latestMetadata, $cassette->metadata());
                foreach ($cassette->exchanges() as $exchange) {
                    $allExchanges[] = $exchange;
                }
            }
        }

        return new Cassette($version, $allExchanges, $latestMetadata);
    }

    #[Override]
    public function save(string $name, Cassette $cassette): void
    {
        $safeName = $this->sanitizeBaseName($name);
        $chunkSize = $this->maxExchangesPerFile > 0 ? $this->maxExchangesPerFile : \PHP_INT_MAX;
        $chunks = array_chunk($cassette->exchanges(), $chunkSize);
        if ($chunks === []) {
            $chunks = [[]];
        }

        foreach ($chunks as $index => $chunk) {
            $chunkFileName = sprintf('%s-%04d', $safeName, $index + 1);
            $this->innerStore->save(
                $chunkFileName,
                new Cassette($cassette->version(), $chunk, $cassette->metadata())
            );
        }

        $this->pruneOldFiles($safeName);
    }

    /**
     * @return list<string> Sorted file names without extension/path for loading
     */
    private function getRotatedFiles(string $name): array
    {
        $safeName = $this->sanitizeBaseName($name);
        $dir = rtrim($this->baseDir, '/');

        if (!is_dir($dir)) {
            return [];
        }

        $pattern = $dir . '/' . $safeName . '*.json';
        $matched = glob($pattern);
        if ($matched === false || $matched === []) {
            return [];
        }

        $validFiles = [];
        foreach ($matched as $fullPath) {
            $fileName = basename($fullPath);
            // Skip lock files or temporary files
            if (str_ends_with($fileName, '.lock') || str_contains($fileName, '.tmp_')) {
                continue;
            }
            $validFiles[] = $fileName;
        }

        sort($validFiles, \SORT_NATURAL);

        return $validFiles;
    }

    private function pruneOldFiles(string $safeName): void
    {
        if ($this->maxFiles <= 0) {
            return;
        }

        $files = $this->getRotatedFiles($safeName);
        $dir = rtrim($this->baseDir, '/');

        if (count($files) > $this->maxFiles) {
            $toDeleteCount = count($files) - $this->maxFiles;
            for ($i = 0; $i < $toDeleteCount; $i++) {
                $filePath = $dir . '/' . $files[$i];
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }
        }
    }

    private function sanitizeBaseName(string $name): string
    {
        $base = basename($name);
        if (str_ends_with($base, '.json')) {
            $base = substr($base, 0, -5);
        }
        return $base;
    }
}
