# Changelog

All notable changes to `cleatsquad/php-http-replay` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-15

### Added

- `ExecutionMode::RecordOnce` / `OnMiss`: Replays recorded exchanges when present in cassette, automatically executes real HTTP request, sanitizes and appends to cassette when missing.
- `InMemoryCassetteStore`: Fast RAM-only storage implementation for zero-I/O unit testing.
- `CassetteNamingStrategyInterface`: Pluggable naming strategies (`StaticCassetteNamingStrategy`, `CallbackCassetteNamingStrategy`), allowing dynamic cassette name resolution.
- Explicit JSON path sanitization in `DefaultSanitizer`: Support for `sensitiveJsonPaths` (e.g. `$.user.profile.token` or `payment.card.number`) with recursive array and nested object traversal.
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

[1.0.0]: https://github.com/CleatSquad/php-http-replay/releases/tag/v1.0.0
