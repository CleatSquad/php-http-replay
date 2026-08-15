# Upstream PHP-VCR Proposal Candidates

The following proposal matrix identifies capabilities designed in `cleatsquad/php-http-replay` that can be generically contributed upstream to [`php-vcr/php-vcr`](https://github.com/php-vcr/php-vcr).

---

## Proposal 1: Semantic JSON Request Matcher

- **Feature**: Semantic JSON Request Matching
- **Problem**: PHP-VCR compares request bodies using exact string equality. JSON requests with identical semantic content but different object key ordering fail replay matching.
- **Current PHP-VCR Behavior**: Naive string equality comparison in `Request::matches()`.
- **Proposed Upstream Improvement**: Add an optional `json_body` matcher callback or rule in `VCR\Configuration` that decodes JSON, ignores object key ordering, preserves array element ordering, and compares scalar types strictly.
- **Backwards Compatibility**: 100% backward compatible (enabled optionally or as default fallback for `application/json` content-type).
- **Test Strategy**: Unit tests matching `{ "a": 1, "b": 2 }` with `{ "b": 2, "a": 1 }`.
- **Why Generic**: Standard issue in all modern JSON REST/GraphQL API testing.
- **Relationship to `php-http-replay`**: Directly adapted from `CleatSquad\HttpReplay\Matcher\DefaultRequestMatcher`.

---

## Proposal 2: Structured Mismatch Diagnostics

- **Feature**: Structured Mismatch Diagnostic Objects
- **Problem**: When a request fails to match recorded cassette exchanges, PHP-VCR returns `false` or throws generic exceptions without detailing which specific parameter, header, path, or body key differed.
- **Current PHP-VCR Behavior**: `Request::matches()` returns `bool`.
- **Proposed Upstream Improvement**: Introduce a `MatchResult` / `Difference` model that collects field-level mismatch reasons (e.g., `body.messages.0.content: expected 'hello', actual 'bye'`) and includes them in replay exception messages.
- **Backwards Compatibility**: Non-breaking additive API.
- **Test Strategy**: Unit tests asserting detailed error output upon request mismatch.
- **Why Generic**: Essential for debugging test failures in large cassette suites.
- **Relationship to `php-http-replay`**: Directly derived from `CleatSquad\HttpReplay\Model\MatchResult` and `CleatSquad\HttpReplay\Model\Difference`.

---

## Proposal 3: Secret & Credential Sanitization Hooks

- **Feature**: Built-in Secret Sanitizer for Recorded Cassettes
- **Problem**: PHP-VCR records raw HTTP interactions, potentially writing API tokens (`Authorization: Bearer ...`), session cookies, and private API keys directly into committed YAML cassettes.
- **Current PHP-VCR Behavior**: Requires users to manually hook into events or write custom storage wrappers.
- **Proposed Upstream Improvement**: Add a configurable `Sanitizer` pipeline to `VCR\Configuration` that automatically redacts sensitive headers and JSON body keys before storing recordings on disk.
- **Backwards Compatibility**: Non-breaking optional configuration.
- **Test Strategy**: Verification tests ensuring live requests remain unsanitized while persisted recordings contain redacted tokens.
- **Why Generic**: Critical security requirement for public open-source codebases using VCR testing.
- **Relationship to `php-http-replay`**: Directly derived from `CleatSquad\HttpReplay\Sanitizer\DefaultSanitizer`.

---

## Proposal 4: Instance-Local PSR-18 / Guzzle Handler Integration

- **Feature**: Instance-Local Transport Adapter (PSR-18 & Guzzle Handler)
- **Problem**: PHP-VCR relies on global runtime interception (cURL hooks and stream wrapper overrides), which can cause side effects in complex test runners or parallel process executions.
- **Current PHP-VCR Behavior**: Global stream wrapper / cURL library hooks.
- **Proposed Upstream Improvement**: Expose an instance-local PSR-18 `ClientInterface` and Guzzle Handler stack middleware for dependency-injected HTTP clients.
- **Backwards Compatibility**: Non-breaking additive feature.
- **Test Strategy**: Isolated unit tests using fake PSR-18 clients without global hooks.
- **Why Generic**: Essential for modern PHP applications using PSR-18 client injection.
- **Relationship to `php-http-replay`**: Directly derived from `CleatSquad\HttpReplay\Engine\HttpReplayEngine` and `CleatSquad\HttpReplay\Integration\Guzzle\GuzzleReplayHandler`.
