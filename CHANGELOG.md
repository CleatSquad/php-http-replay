# Changelog

All notable changes to `cleatsquad/php-http-replay` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.1] - 2026-09-06

### Fixed

- `guzzlehttp/guzzle` and `symfony/yaml` moved from `require-dev` to `require`. `src/PhpVcr/PhpVcrCassetteImporter.php`, the Guzzle integration and `JsonCassetteStore` all import classes from both, so a `--no-dev` install left them undefined at runtime.

## [2.1.0] - 2026-08-27

### Added

- `RotatingJsonCassetteStore`: caps exchanges per file and total files retained, pruning the oldest file once the limit is exceeded.
- Exchange TTL: an exchange older than the configured lifetime is treated as expired during replay instead of being matched forever.
- `OptionsAwareClientInterface` and `GuzzleOptionsAwareClient`: lets a real client honor per-request options (`timeout`, `connect_timeout`) that plain PSR-18 `sendRequest()` has no slot for, forwarded only in Passthrough/Record/RecordOnce.
- `docs/upstream/php-vcr/`: what of this library could be contributed back to [`php-vcr/php-vcr`](https://github.com/php-vcr/php-vcr), with the review, proposals and evidence behind each.

## [2.0.0] - 2026-08-15

### BREAKING CHANGES

- `JsonCassetteStore` writes schema `version: 2`. Version 1 cassettes are still read, and a cassette saved by this release is stamped version 2, so releases before 2.0.0 can no longer read it. The store stamps the schema itself: the version carried by a `Cassette` no longer decides what is written to disk.
- `HttpReplayEngine` delegates exchange selection to `ExchangeSelectorInterface` instead of walking the cassette itself. Selection behaviour is unchanged for both matching modes.

### Added

- `ExchangeSelectorInterface` contract and `ExchangeSelectionResult` model for clean separation of exchange selection algorithms.
- `SequentialExchangeSelector` and `UnorderedExchangeSelector` implementations, plus a `selector` constructor argument on `HttpReplayEngine` for a custom strategy.
- `flock` exclusive write lock in `JsonCassetteStore::save()`, so two processes recording into the same cassette no longer race on the temporary file.
- Structured `toArray()` method on `MatchResult` for CI machine readability.

## [1.4.0] - 2026-08-15

### Added

- `ExecutionMatchingMode` enum (`Sequential` and `Unordered`): Configurable matching strategy in `HttpReplayEngine`. Defaults strictly to `Sequential` to preserve exact backward compatibility.
- Non-sequential opt-in matching (`ExecutionMatchingMode::Unordered`): Allows replaying HTTP exchanges in arbitrary arrival order while ensuring each recorded exchange is consumed at most once.
- `UnorderedMismatchException`: Typed exception providing rich diagnostics on unordered matching failure (inspected count, consumed count, best candidate index, context).
- `matchingMode` field in `ReplayStats`: Immutable reporting of the active matching mode.

## [1.3.0] - 2026-08-15

### Added

- `ReplayStats` model and `HttpReplayEngine::stats()` inspection method: Audits cassette consumption during a test session (`cassetteName`, `totalExchanges`, `replayedCount`, `recordedCount`, `unusedIndices`, `isFullyConsumed()`, `hasUnusedExchanges()`). `unusedIndices` reports only exchanges the cassette already held and that were never replayed, so an exchange recorded during the session is not counted as unused.

## [1.2.0] - 2026-08-15

### Added

- Cassette integrity checksum: `JsonCassetteStore` stamps every cassette with a `metadata.checksum` entry (`sha256:<hash>` over the recorded exchanges) and rejects a file whose content no longer matches it with an `InvalidCassetteException`. The checksum is recomputed on each save, so appending exchanges in `Record` or `RecordOnce` mode keeps the cassette valid. It detects corruption and accidental edits; being stored unsigned in the file it covers, it is not a protection against deliberate tampering. Cassettes written before this release carry no checksum and are loaded without verification.

### Fixed

- `DefaultSanitizer` no longer returns an unreadable body when the incoming PSR-7 stream is not seekable: the sanitized message carries a fresh seekable stream so downstream consumers can read it.

## [1.1.1] - 2026-08-15

### Added

- `ExecutionMode::RecordOnce`: Replays recorded exchanges when present in cassette, automatically executes real HTTP request, sanitizes and appends to cassette when missing.
- `InMemoryCassetteStore`: Fast RAM-only storage implementation for zero-I/O unit testing.
- `CassetteNamingStrategyInterface`: Pluggable naming strategies (`StaticCassetteNamingStrategy`, `CallbackCassetteNamingStrategy`), allowing dynamic cassette name resolution.
- Explicit JSON path sanitization in `DefaultSanitizer`: Support for `sensitiveJsonPaths` (e.g. `$.user.profile.token` or `payment.card.number`) with recursive array and nested object traversal. The parameter is appended at the end of the constructor signature, so positional calls written against 1.0.0 keep working; it is meant to be passed as a named argument.
- CLI diagnostic formatting: `toCliString(bool $colorize = false)` on `Difference`, `MatchResult`, and `RequestMismatchException`.
- Typed sequence exceptions: `SequenceExhaustedException` and `SequenceMismatchException` for granular sequence reporting in CI.

## [1.0.0] - 2026-08-15

Initial release.

### Added

- `HttpReplayEngine`, a PSR-18 client that replays, records or passes through
  HTTP exchanges according to an `ExecutionMode`.
- `ExecutionMode`, with `Replay` (no network call at all), `Record` (real call,
  sanitized cassette written) and `Passthrough`.
- `CassetteStoreInterface` and `JsonCassetteStore`, persisting cassettes as
  atomically written, schema-versioned UTF-8 JSON.
- `RequestMatcherInterface` and `DefaultRequestMatcher`, matching requests
  semantically: object key order is ignored, array order is significant, scalar
  types are compared strictly, and query parameters are compared as a set.
- `MatchResult` and `Difference`, reporting mismatches down to the failing path
  such as `body.messages.0.content`, so a failed replay says what differed.
- `SanitizerInterface` and `DefaultSanitizer`, redacting secrets in headers, in
  JSON bodies recursively, and in URI query parameters.
- `GuzzleReplayHandler`, an instance-local Guzzle handler, so replay is scoped
  to one client instead of a global interceptor.
- `PhpVcrCassetteImporter`, reading legacy `php-vcr` YAML cassettes.

### Security

- Sanitization applies to what is persisted, never to what is sent: `Record`
  mode reaches the remote API with real credentials and writes `[REDACTED]` to
  the cassette.
- `Replay` mode performs no network call, so a cassette can never leak a
  credential to a remote host during a test run.
- Cassette file names are reduced to their basename, so a cassette name cannot
  traverse out of the configured directory.

[2.0.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v2.0.0
[1.4.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.4.0
[1.3.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.3.0
[1.2.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.2.0
[1.1.1]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.1.1
[1.0.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.0.0
