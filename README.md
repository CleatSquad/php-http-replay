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

Switching `ExecutionMode::Replay` to `ExecutionMode::Record` performs the real
request and writes the sanitized exchange to the cassette. `ExecutionMode::Passthrough`
disables the mechanism entirely without touching the calling code.

The handler is instance-local: it applies to the client you gave it to, and
leaves every other HTTP client in the process alone.

### Matching

`DefaultRequestMatcher` compares method, URI, headers and body. JSON bodies are
compared semantically: object key order is ignored, array element order is
significant, and scalar types are compared strictly, so `1` does not match
`"1"`. Query parameters are compared as a set.

When nothing matches, `RequestMismatchException` names the failing path, for
instance `body.messages.0.content`, along with the expected and actual values.

Headers that change on every run are ignored by default: `Authorization`,
`Cookie`, `User-Agent`, `Date`, `Content-Length` and a few others. The
constructor takes the complete replacement list, so passing your own set
replaces the defaults rather than adding to them.

### Sanitization

`DefaultSanitizer` redacts secrets in headers, recursively in JSON bodies, and
in URI query parameters such as `?api_key=`, `?token=` and `?secret=`.

Sanitization applies to what is stored, never to what is sent. In `Record`
mode the remote API receives your real credentials and the cassette receives
`[REDACTED]` — which is what makes a cassette safe to commit. Review a cassette
before committing it the first time: a secret this library does not recognize
is a secret it will not redact.

### Storage

`JsonCassetteStore` writes UTF-8 JSON atomically, through a temporary file and
a rename, and stamps each cassette with a schema `version` so a future format
change stays detectable rather than silently misread. Cassette names are
reduced to their basename, so a name cannot escape the configured directory.

Implement `CassetteStoreInterface` to store cassettes anywhere else.

### Importing php-vcr cassettes

```php
use CleatSquad\HttpReplay\PhpVcr\PhpVcrCassetteImporter;

$cassette = PhpVcrCassetteImporter::fromYaml(__DIR__ . '/fixtures/legacy.yml');
```

Importing preserves the recording as it was, including any credential the
legacy cassette contained. Sanitization happens when the cassette is written
back through the store.

## Limitations

- **Asynchronous execution**: `GuzzleReplayHandler` returns Guzzle promises,
  but the underlying replay and record paths are synchronous (PSR-18
  `sendRequest`), so promises settle on invocation. True event-loop streaming
  is out of scope.
- **Streaming and SSE**: transport-level timing and chunk boundaries for
  Server-Sent Events are not reproduced. Stream bodies remain readable in full
  through PSR-7.
- **PSR-17 factories**: outside a Guzzle environment, pass the PSR-17 factories
  (`RequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface`)
  to `JsonCassetteStore` so it can build messages with your own implementations.

## Public API

Everything under `src/` that is `public` follows [Semantic Versioning](https://semver.org),
except `src/Internal/`, which is excluded and may change in any release.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and pull requests are
welcome.

## License

MIT. Copyright (c) 2026 Mohamed El Mrabet. See [LICENSE](LICENSE).
