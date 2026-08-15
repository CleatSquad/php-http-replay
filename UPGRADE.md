# Upgrade Guide

This guide details backward compatibility notes and migration instructions for `cleatsquad/php-http-replay`.

---

## Upgrading from v1.0.0 to v1.1.0

`v1.1.0` is **100% backward-compatible** with `v1.0.0`. No code changes are required for existing integrations.

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

#### 6. Cassette Integrity Checksum Verification

`JsonCassetteStore` automatically computes a SHA-256 hash of recorded exchanges on `save()` and verifies it on `load()`. Existing cassettes generated in `v1.0.0` without a checksum field continue to load seamlessly.
