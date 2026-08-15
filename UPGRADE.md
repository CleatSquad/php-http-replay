# Upgrade Guide

This guide details backward compatibility notes and migration instructions for `cleatsquad/php-http-replay`.

---

## Upgrading from v1.0.0 to v1.1.1

`v1.1.1` is **100% backward-compatible** with `v1.0.0`. No code changes are required for existing integrations.

### Key Additions & New Capabilities

#### 1. New Execution Mode: `RecordOnce`

Use `ExecutionMode::RecordOnce` when you want a test to replay recorded HTTP exchanges if present in the cassette, or automatically perform a live network call, sanitize the exchange, and append it to the cassette when missing.

```php
use CleatSquad\HttpReplay\Enum\ExecutionMode;

$engine = new HttpReplayEngine(
    ExecutionMode::RecordOnce,
    $store,
    'my_cassette',
    $matcher,
    $sanitizer,
    $realHttpClient // Required for missing exchange recording
);
```

#### 2. In-Memory Cassette Storage (`InMemoryCassetteStore`)

For zero-I/O unit tests without disk access:

```php
use CleatSquad\HttpReplay\Storage\InMemoryCassetteStore;

$store = new InMemoryCassetteStore();
```

#### 3. Cassette Naming Strategies

Instead of passing a static string cassette name, you can pass an implementation of `CassetteNamingStrategyInterface`:

```php
use CleatSquad\HttpReplay\Naming\CallbackCassetteNamingStrategy;

$namingStrategy = new CallbackCassetteNamingStrategy(fn () => 'dynamic_test_' . $testId);
$engine = new HttpReplayEngine($mode, $store, $namingStrategy, $matcher, $sanitizer);
```

#### 4. Explicit JSON Path Sanitization

Target nested secrets using dot-notation JSON path subset:

```php
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;

$sanitizer = new DefaultSanitizer(
    sensitiveHeaders: ['authorization'],
    sensitiveBodyKeys: ['password'],
    sensitiveQueryParams: ['token'],
    replacement: '[REDACTED]',
    sensitiveJsonPaths: ['$.user.profile.token', 'payment.card.number']
);
```

#### 5. Formatted CLI Diagnostics

Format match results or exceptions cleanly for console or CI logs:

```php
try {
    $engine->sendRequest($request);
} catch (RequestMismatchException $e) {
    echo $e->toCliString(colorize: true);
}
```

---

## Upgrading from v1.1.1 to v1.2.0

`v1.2.0` is **100% backward-compatible** with `v1.1.1`. No code changes are required for existing integrations.

### Key Additions & New Capabilities

#### 1. Cassette Integrity Checksum Verification

`JsonCassetteStore` automatically computes a SHA-256 hash of recorded exchanges on `save()` and verifies it on `load()`. Existing cassettes generated in `v1.0.0` and `v1.1.1` without a checksum field continue to load seamlessly.

The hash is stored under `metadata.checksum` as `sha256:<hash>` and is recomputed on every save, so appending exchanges in `Record` or `RecordOnce` mode keeps the cassette valid. Loading a file whose exchanges no longer match its checksum raises an `InvalidCassetteException`:

```text
Malformed cassette at "…/openai_chat.json": Cassette checksum mismatch.
File content does not match its recorded checksum.
```

If you edit a cassette by hand, delete its `metadata.checksum` entry: a cassette without a checksum loads without verification and is stamped again on the next save.

The checksum is stored unsigned inside the file it covers, so it detects corruption and accidental edits, not deliberate tampering.

#### 2. Non-Seekable PSR-7 Stream Support

`DefaultSanitizer` now returns a sanitized message carrying a fresh seekable stream when the incoming body is not seekable, so downstream consumers can still read it. Previously such a body was already consumed and read back as an empty string.

---

## Upgrading from v1.2.0 to v1.3.0

`v1.3.0` is **100% backward-compatible** with `v1.2.0`. No code changes are required for existing integrations.

### Key Additions & New Capabilities

#### 1. Cassette Consumption Audit (`ReplayStats`)

`HttpReplayEngine::stats()` returns a `ReplayStats` snapshot of what the engine did during the session, so a test tear-down can fail on a cassette that drifted out of sync with the code it covers:

```php
$stats = $engine->stats();

$this->assertFalse($stats->hasUnusedExchanges(), sprintf(
    'Cassette "%s" holds unused exchanges at indices: %s',
    $stats->cassetteName,
    implode(', ', $stats->unusedIndices)
));
```

`unusedIndices` covers only exchanges that were already in the cassette and were never replayed; exchanges recorded during the session by `Record` or `RecordOnce` are not reported as unused. Reading the stats loads the cassette from the store, so call it once per assertion rather than in a loop.
