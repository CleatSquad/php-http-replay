# PHP HTTP Replay

[![Latest Version](https://img.shields.io/packagist/v/cleatsquad/php-http-replay.svg)](https://packagist.org/packages/cleatsquad/php-http-replay)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](composer.json)

Deterministic HTTP request recording and replay for PHP built on PSR-7 and PSR-18.

A test that calls a real API is slow, costs money, and fails for reasons that
have nothing to do with your code. Record the exchange once, replay it forever
after: the same request returns the same response, offline, in milliseconds.
When the request no longer matches what was recorded, you get told exactly
which field differed — not just that something did.

## Architecture

`php-http-replay` separates the replay pipeline into four independent layers:
- **Interception (`HttpReplayEngine`)**: PSR-18 client orchestrating replay, record, record-once, and passthrough modes.
- **Selection (`ExchangeSelectorInterface`)**: Pluggable exchange selection strategies ([`SequentialExchangeSelector`](src/Selector/SequentialExchangeSelector.php), [`UnorderedExchangeSelector`](src/Selector/UnorderedExchangeSelector.php)).
- **Matching (`RequestMatcherInterface`)**: Semantic JSON and HTTP request comparison producing detailed [`MatchResult`](src/Model/MatchResult.php) diagnostics.
- **Cassette Format v2**: Versioned schema (`version: 2`) with SHA-256 checksum integrity and backward-compatible loading of legacy v1 cassettes.

## Installation

```bash
composer require --dev cleatsquad/php-http-replay
```

Requires PHP 8.2 or later. The core depends on PSR interfaces only
(`psr/http-message`, `psr/http-client`, `psr/http-factory`); Guzzle and
Symfony YAML are optional and used only by the integration and import layers.

See [UPGRADE.md](UPGRADE.md) for upgrade notes and compatibility guides between major/minor versions.

## Usage

```php
use CleatSquad\HttpReplay\Engine\HttpReplayEngine;
use CleatSquad\HttpReplay\Enum\ExecutionMode;
use CleatSquad\HttpReplay\Integration\Guzzle\GuzzleReplayHandler;
use CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher;
use CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer;
use CleatSquad\HttpReplay\Storage\JsonCassetteStore;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$engine = new HttpReplayEngine(
    ExecutionMode::Replay,
    new JsonCassetteStore(__DIR__ . '/fixtures/cassettes'),
    'openai_chat_cassette',
    new DefaultRequestMatcher(),
    new DefaultSanitizer()
);

$client = new Client(['handler' => HandlerStack::create(new GuzzleReplayHandler($engine))]);

// No network call: the recorded PSR-7 response is returned as is.
$response = $client->post('https://api.openai.com/v1/chat/completions', [
    'json' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
]);
```

### Execution Modes

- `ExecutionMode::Replay` : Performs no network calls and replays recorded responses from the cassette.
- `ExecutionMode::Record` : Sends real HTTP requests, sanitizes the response, appends the exchange to the cassette, and returns the response.
- `ExecutionMode::RecordOnce` : Replays if a matching exchange exists in the cassette; executes real network call, sanitizes, and appends to cassette if missing.
- `ExecutionMode::Passthrough` : Bypasses the replay engine and performs live HTTP calls without modifying cassettes.

### Matching Modes & Strategies

By default, requests are matched sequentially (`ExecutionMatchingMode::Sequential`). You can configure non-sequential, out-of-order matching via `ExecutionMatchingMode::Unordered` (OPT-IN):

```php
use CleatSquad\HttpReplay\Enum\ExecutionMatchingMode;

$engine = new HttpReplayEngine(
    ExecutionMode::Replay,
    $cassetteStore,
    'cassette_name',
    $requestMatcher,
    $sanitizer,
    matchingMode: ExecutionMatchingMode::Unordered,
);
```

- **`ExecutionMatchingMode::Sequential` (Default)**: Requests must match cassette exchanges in exact sequential order. Preserves deterministic response order for repeated identical requests.
- **`ExecutionMatchingMode::Unordered` (OPT-IN)**: Requests can match any available, unconsumed exchange in the cassette. Useful for asynchronous, parallel, or non-deterministic test runners where request dispatch order varies. Once consumed, an exchange cannot be replayed.

> **Performance & Trade-offs**: In `Sequential` mode, matching is $O(1)$ per request. In `Unordered` mode, matching is up to $O(N)$ per request where $N$ is the number of exchanges in the cassette. For large cassettes ($N > 1000$), `Sequential` mode is strongly recommended.

### Matching

`DefaultRequestMatcher` compares method, URI, headers and body. JSON bodies are
compared semantically: object key order is ignored, array element order is
significant, and scalar types are compared strictly, so `1` does not match
`"1"`. Query parameters are compared as a set.

When nothing matches, `RequestMismatchException` (or `UnorderedMismatchException` in unordered mode) names the failing path, for
instance `body.messages.0.content`, along with the expected and actual values.

### Diagnostics & CLI Output

`Difference`, `MatchResult`, and `RequestMismatchException` offer multi-line CLI diagnostic output:

```php
try {
    $engine->sendRequest($request);
} catch (RequestMismatchException $e) {
    echo $e->toCliString(colorize: true);
}
```

Output:
```text
Request mismatch in cassette "openai_chat" at index 0:
  body.messages.0.content: expected "Hello", actual "Bonjour"
```

### Sanitization & JSON Path Redaction

`DefaultSanitizer` redacts secrets in headers, URI query parameters, recursively in JSON bodies by key name, and by explicit JSON paths/pointers (e.g., `$.user.profile.token` or `payment.card.number`):

```php
$sanitizer = new DefaultSanitizer(
    sensitiveHeaders: ['authorization', 'x-api-key'],
    sensitiveBodyKeys: ['api_key', 'token', 'secret'],
    sensitiveQueryParams: ['api_key', 'token'],
    replacement: '[REDACTED]',
    sensitiveJsonPaths: ['$.user.profile.token', 'payment.card.number'],
);
```

### Storage Backends & Checksum Integrity

- `JsonCassetteStore` : Writes UTF-8 JSON atomically through a temporary file and `LOCK_EX` rename, stamped with a schema version and automatic SHA-256 integrity checksum (`sha256:<hash>`).
- `InMemoryCassetteStore` : RAM-only cassette store for fast, zero-I/O unit tests.

### Replay Audit Trail & Inspection

`HttpReplayEngine::stats()` returns a `ReplayStats` snapshot to inspect cassette consumption:

```php
$stats = $engine->stats();
echo "Replayed: {$stats->replayedCount}/{$stats->totalExchanges}\n";

if ($stats->hasUnusedExchanges()) {
    echo "Unused exchange indices: " . implode(', ', $stats->unusedIndices);
}

if ($stats->isFullyConsumed()) {
    echo "All cassette exchanges were executed successfully.";
}
```

`unusedIndices` lists the exchanges the cassette already held and that were never
replayed, which is what a stale cassette looks like. An exchange recorded during
the session by `Record` or `RecordOnce` is never counted as unused.

### Cassette Naming Strategies

Use `CassetteNamingStrategyInterface` for dynamic cassette resolution:

```php
use CleatSquad\HttpReplay\Naming\CallbackCassetteNamingStrategy;

$naming = new CallbackCassetteNamingStrategy(fn () => 'test_' . $testId);
```

### Importing php-vcr cassettes

```php
use CleatSquad\HttpReplay\PhpVcr\PhpVcrCassetteImporter;

$cassette = PhpVcrCassetteImporter::fromYaml(__DIR__ . '/fixtures/legacy.yml');
```

## Optional Community Integrations

`php-http-replay` is fully autonomous and zero-dependency. Community integration packages can be installed separately:

- `cleatsquad/php-http-replay-vcr` (External adapter for legacy PHP-VCR storage)
- `cleatsquad/php-http-replay-vcr-plugin` (External adapter for HTTPlug pipeline)
- `cleatsquad/php-http-replay-phpunit` (Tooling package for PHPUnit 11 `#[Cassette]` attributes)

## Limitations

- **Asynchronous execution**: `GuzzleReplayHandler` returns Guzzle promises,
  but the underlying replay and record paths are synchronous (PSR-18
  `sendRequest`), so promises settle on invocation. True event-loop streaming
  is out of scope.
- **Streaming and SSE**: transport-level timing and chunk boundaries for
  Server-Sent Events are not reproduced. Stream bodies remain readable in full
  through PSR-7.

## Public API

Everything under `src/` that is `public` follows [Semantic Versioning](https://semver.org),
except `src/Internal/`, which is excluded and may change in any release.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — components, and where a request is
  intercepted.
- [docs/upstream/php-vcr/](docs/upstream/php-vcr/) — what of this library can be
  contributed back to [`php-vcr/php-vcr`](https://github.com/php-vcr/php-vcr):
  the [review](docs/upstream/php-vcr/review.md) and its decision matrix, the
  four [proposals](docs/upstream/php-vcr/proposals.md), and the evidence behind
  each. Proposal #1 is an open draft PR upstream.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [UPGRADE.md](UPGRADE.md). Bug reports and pull requests are
welcome.

## License

MIT. Copyright (c) 2026 Mohamed El Mrabet. See [LICENSE](LICENSE).
