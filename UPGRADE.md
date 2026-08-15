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

---

## Upgrading from v1.3.0 to v1.4.0

`v1.4.0` is **100% backward-compatible** with `v1.3.0`. Matching stays sequential unless you opt in, so no code changes are required for existing integrations.

### Key Additions & New Capabilities

#### 1. Opt-In Unordered Matching (`ExecutionMatchingMode`)

Pass `matchingMode: ExecutionMatchingMode::Unordered` when the order in which your suite dispatches requests is not deterministic:

```php
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;

$engine = new HttpReplayEngine(
    ExecutionMode::Replay,
    $store,
    'my_cassette',
    $matcher,
    $sanitizer,
    matchingMode: ExecutionMatchingMode::Unordered,
);
```

Each recorded exchange is still consumed at most once, so repeated identical requests keep getting distinct recorded responses. `Replay` and `RecordOnce` honour the mode; `Record` and `Passthrough` are unaffected by it.

Matching is O(1) per request in `Sequential` mode and up to O(N) in `Unordered` mode, N being the number of exchanges in the cassette. Keep `Sequential` for large cassettes.

#### 2. `UnorderedMismatchException`

When no unconsumed exchange matches, the engine raises `UnorderedMismatchException` instead of a sequence exception. It extends `RequestMismatchException`, so an existing `catch` block still catches it, and adds `inspectedCount()`, `consumedCount()`, `bestCandidateIndex()` and `context()` for diagnosing which recorded exchange came closest.

#### 3. `ReplayStats::$matchingMode`

The stats snapshot reports the strategy the engine ran with. The property is appended to the constructor with a `Sequential` default, so code building a `ReplayStats` by hand keeps working.

---

## Upgrading from v1.4.0 to v2.0.0

`v2.0.0` changes the cassette file format and the internals of exchange selection. Test suites that only consume the public API need no code change; the format change is what makes this a major release.

### Breaking Changes

#### 1. Cassettes are written with `"version": 2`

`JsonCassetteStore` now writes schema version 2. It still reads version 1 cassettes, so nothing has to be converted by hand, but a cassette re-saved by this release is written as version 2 and **older releases of this package cannot read it**. If a cassette file is shared with a project still on v1.x, keep that project's cassettes out of a recording run until it is upgraded too.

Version 2 files carry the SHA-256 `metadata.checksum` introduced in v1.2.0 and are written under an exclusive `flock`, so two processes recording into the same cassette no longer race on the temporary file.

#### 2. Exchange selection moved behind `ExchangeSelectorInterface`

`HttpReplayEngine` no longer walks the cassette itself: it asks an `ExchangeSelectorInterface` for a candidate. `SequentialExchangeSelector` and `UnorderedExchangeSelector` implement the two strategies, and the engine picks one from `ExecutionMatchingMode` exactly as before. Default behaviour is unchanged.

You can now pass your own selector as the last constructor argument to implement a different strategy:

```php
$engine = new HttpReplayEngine(
    ExecutionMode::Replay,
    $store,
    'my_cassette',
    $matcher,
    $sanitizer,
    selector: new MyOwnExchangeSelector(),
);
```

A selector receives the request, the recorded exchanges, the indices already consumed in this session and the matcher, and returns an `ExchangeSelectionResult` — a match, a mismatch with the closest candidate, or an exhausted sequence.

### Added

#### `MatchResult::toArray()`

Alongside `toCliString()`, `toArray()` returns `['matched' => bool, 'differences' => array]` for a CI report that consumes JSON rather than console text.
