# PHP HTTP Replay

[![Latest Version](https://img.shields.io/packagist/v/cleatsquad/php-http-replay.svg)](https://packagist.org/packages/cleatsquad/php-http-replay)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](composer.json)

Deterministic HTTP request recording and replay for PHP, built on PSR-7 and
PSR-18.

A test that calls a real API is slow, costs money, and fails for reasons that
have nothing to do with your code. Record the exchange once, replay it forever
after: the same request returns the same response, offline, in milliseconds.
When the request no longer matches what was recorded, you get told exactly
which field differed — not just that something did.

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

### Matching

`DefaultRequestMatcher` compares method, URI, headers and body. JSON bodies are
compared semantically: object key order is ignored, array element order is
significant, and scalar types are compared strictly, so `1` does not match
`"1"`. Query parameters are compared as a set.

When nothing matches, `RequestMismatchException` names the failing path, for
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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [UPGRADE.md](UPGRADE.md). Bug reports and pull requests are
welcome.

## License

MIT. Copyright (c) 2026 Mohamed El Mrabet. See [LICENSE](LICENSE).
