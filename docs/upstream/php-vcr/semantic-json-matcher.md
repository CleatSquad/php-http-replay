# Upstream proposal #1 — Semantic JSON request body matcher

**Status:** SUBMITTED as a draft PR — [php-vcr/php-vcr#505](https://github.com/php-vcr/php-vcr/pull/505)
(branch `mohaelmrabet:feat/json-body-matcher`, 2026-08-15)

> **Implementation note.** The shipped code drops the `(object) ['value' => ...]` wrapper sketched below:
> a JSON *object or array* can never decode to `null`, so `null` is already an unambiguous "not JSON"
> sentinel and `decodeJsonBody()` simply returns `array|\stdClass|null`. Everything else — stdClass
> decoding, `JSON_BIGINT_AS_STRING`, the fallback rules, the last-position registration — shipped as
> designed. A third test was added beyond the plan: an end-to-end record/replay case in `CassetteTest`,
> required by `CONTRIBUTING.md`'s rule that every documented example must be exercised.
**Target repository:** `php-vcr/php-vcr`
**Target branch:** `master` (1.x line — additive, no BC break)
**Source of truth for this analysis:** `php-vcr/php-vcr@master` (`src/VCR/RequestMatcher.php`,
`src/VCR/Configuration.php`, `src/VCR/Request.php`, `src/VCR/Cassette.php`, `tests/Unit/RequestMatcherTest.php`,
`docs/reference/request-matchers.md`) plus the copy installed in this project
(`dev-feat/441-symfony-8`, ref `6fcdbb5`) — `RequestMatcher` is byte-identical on both.

---

## PR TITLE

```
feat(matcher): add opt-in `body_json` request matcher for semantic JSON body comparison
```

PR template header:

| Q               | A                                                     |
| --------------- | ----------------------------------------------------- |
| Type            | Feature                                               |
| Fixes           | *(none — new issue to be opened first, see below)*    |
| BC break?       | no                                                    |
| Deprecation?    | no                                                    |
| New dependency? | no                                                    |
| Docs updated?   | yes                                                   |
| License         | MIT                                                   |

---

## MOTIVATION

`RequestMatcher::matchBody()` compares request bodies as raw strings:

```php
public static function matchBody(Request $storedRequest, Request $request): bool
{
    return $storedRequest->getBody() === $request->getBody();
}
```

For any API whose request bodies are JSON, this makes replay depend on the *byte layout* of the payload
rather than on its meaning. Two requests that are indistinguishable to the server fail to match when:

- the client serialises an associative array whose key insertion order changed (a refactor that moves a
  field, an SDK upgrade, a serialiser that sorts keys, `json_encode` of an object vs. an array);
- the payload is pretty-printed on one side and minified on the other;
- a value is `1` on one side and `1` on the other but the surrounding whitespace differs.

The failure mode is bad: the request is *not* replayed, so with `MODE_NONE` the test dies with a
`LogicException`, and with `MODE_NEW_EPISODES` a real HTTP call is silently issued and a near-duplicate
recording is appended to the cassette. The usual workaround — dropping `body` from
`enableRequestMatchers()` — throws away body matching entirely, which is exactly the discrimination the
user needed.

This is not specific to any one API. It applies to every JSON-over-HTTP client: REST APIs, GraphQL
endpoints, JSON-RPC, and LLM provider APIs. The matcher proposed here would be useful to php-vcr users
even if no other package existed.

**Prior art check:** no existing issue or PR proposes a JSON-aware body matcher (searched
`php-vcr/php-vcr` issues/PRs for `json matcher`, `body matcher json`, `matchers`). Issue #446
("Refactor request matchers to objects with cassette-level scope") targets 2.0 and changes *how*
matchers are registered, not which ones exist; this proposal is deliberately written against the
current callback architecture so that it neither blocks nor conflicts with #446.

---

## PROPOSED API

One new built-in matcher, registered under the name `body_json`, backed by one new public static method
`RequestMatcher::matchBodyJson()`. No new class, no new interface, no configuration option.

```php
\VCR\VCR::configure()
    ->enableRequestMatchers(['method', 'url', 'host', 'query_string', 'body_json']);
```

Semantics:

| Case                                             | Result                                        |
| ------------------------------------------------ | --------------------------------------------- |
| Both bodies decode to JSON objects/arrays        | structural comparison (see below)             |
| Either body is not decodable JSON                | falls back to `matchBody()` (strict `===`)    |
| Both bodies `null` or empty                      | falls back to `matchBody()` → match           |
| One body `null`, the other JSON                  | falls back to `matchBody()` → no match        |

Structural comparison rules:

- **object key order is ignored** — `{"a":1,"b":2}` matches `{"b":2,"a":1}`
- **array element order is significant** — `["a","b"]` does **not** match `["b","a"]`
- **objects and arrays are distinct types** — `{}` does not match `[]`
- **scalars are compared strictly** — `1` does not match `"1"`, `1` does not match `1.0`,
  `true` does not match `1`, `null` does not match `""`
- **key sets must be equal** — an extra or missing key is a mismatch (this matcher is not a subset
  matcher)
- **nesting is arbitrary** — the rules apply recursively at every depth

---

## IMPLEMENTATION PLAN

### 1. `src/VCR/RequestMatcher.php`

Append one public method and two private helpers. Nothing existing is touched.

```php
public static function matchBodyJson(Request $storedRequest, Request $request): bool
{
    $storedJson = self::decodeJsonBody($storedRequest->getBody());
    $json = self::decodeJsonBody($request->getBody());

    // Not JSON on at least one side: behave exactly like the `body` matcher.
    if (null === $storedJson || null === $json) {
        return self::matchBody($storedRequest, $request);
    }

    return self::jsonValuesMatch($storedJson->value, $json->value);
}
```

`decodeJsonBody()` returns `null` when the body is absent, empty, or not a JSON *object* or *array*;
otherwise it returns the decoded value. Decoding uses `json_decode($body, false)` — associative mode is
deliberately **not** used, because `json_decode('{"0":"a"}', true)` and `json_decode('["a"]', true)`
produce the same PHP value, which would make an object indistinguishable from an array. Decoding to
`\stdClass` keeps the distinction exact.

```php
private static function decodeJsonBody(?string $body): ?\stdClass /* wrapper */
{
    if (null === $body || '' === trim($body)) {
        return null;
    }

    $decoded = json_decode($body, false, 512, \JSON_BIGINT_AS_STRING);

    if (\JSON_ERROR_NONE !== json_last_error()) {
        return null;
    }

    // Only structured bodies are handled semantically; a bare scalar body
    // (`5`, `"x"`, `true`, `null`) has no ordering ambiguity, so string
    // comparison is both correct and cheaper.
    if (!\is_array($decoded) && !$decoded instanceof \stdClass) {
        return null;
    }

    return (object) ['value' => $decoded];
}
```

*(The single-property wrapper exists only so that `null` unambiguously means "not JSON"; a maintainer
who prefers a `bool &$ok` out-parameter or a private `const NOT_JSON` sentinel will get the same
behaviour — this is a style detail, not a design decision.)*

`JSON_BIGINT_AS_STRING` is used so that integers beyond `PHP_INT_MAX` are compared as their exact
decimal representation instead of being coerced to `float`, where two different integers can collapse to
the same value and produce a false match. It is applied identically to both sides.

```php
private static function jsonValuesMatch(mixed $stored, mixed $value): bool
{
    if ($stored instanceof \stdClass || $value instanceof \stdClass) {
        if (!$stored instanceof \stdClass || !$value instanceof \stdClass) {
            return false;
        }

        $storedVars = get_object_vars($stored);
        $vars = get_object_vars($value);

        if (\count($storedVars) !== \count($vars)) {
            return false;
        }

        foreach ($storedVars as $key => $storedItem) {
            if (!\array_key_exists($key, $vars) || !self::jsonValuesMatch($storedItem, $vars[$key])) {
                return false;
            }
        }

        return true;
    }

    if (\is_array($stored) || \is_array($value)) {
        if (!\is_array($stored) || !\is_array($value) || \count($stored) !== \count($value)) {
            return false;
        }

        foreach ($stored as $index => $storedItem) {
            if (!self::jsonValuesMatch($storedItem, $value[$index])) {
                return false;
            }
        }

        return true;
    }

    return $stored === $value;
}
```

Complexity is linear in the size of the smaller payload; recursion depth is bounded by `json_decode`'s
own depth limit (512).

### 2. `src/VCR/Configuration.php`

One line appended to `$availableRequestMatchers`, **last** in the array:

```php
private $availableRequestMatchers = [
    'method' => [RequestMatcher::class, 'matchMethod'],
    'url' => [RequestMatcher::class, 'matchUrl'],
    'host' => [RequestMatcher::class, 'matchHost'],
    'headers' => [RequestMatcher::class, 'matchHeaders'],
    'body' => [RequestMatcher::class, 'matchBody'],
    'post_fields' => [RequestMatcher::class, 'matchPostFields'],
    'query_string' => [RequestMatcher::class, 'matchQueryString'],
    'soap_operation' => [RequestMatcher::class, 'matchSoapOperation'],
    'body_json' => [RequestMatcher::class, 'matchBodyJson'],
];
```

Position matters: `Request::matches()` short-circuits on the first matcher returning `false`, so with
the default configuration `body` is evaluated (and fails) before `body_json` is ever reached on a
non-matching body. The JSON decode therefore costs nothing on the hot mismatch path.

Registration is required because `enableRequestMatchers()` rejects unknown names. It has a consequence
that must be stated plainly: with the default configuration (`enabledRequestMatchers === null`), *all*
available matchers are enabled, so `body_json` joins the default set. **The observable matching outcome
is nevertheless unchanged**, because `body` and `body_json` are ANDed and `body_json` is weaker than
`body` in every case:

- if `body` returns `true`, the two bodies are the identical string, therefore semantically equal,
  therefore `body_json` returns `true`;
- if `body` returns `false`, the AND is already `false` regardless of `body_json`.

So `body AND body_json ≡ body`. A regression test asserts exactly this (see test plan). The only
default-configuration cost is one `json_decode` per candidate recording in the *matching* case.

A reviewer who considers even a provably-neutral addition to the implicit default set unacceptable can
instead keep `body_json` out of the "all" default by holding it in a separate array consulted only by
`enableRequestMatchers()`/`addRequestMatcher()` name resolution. That variant is a ~10-line
`Configuration` change and the proposal works either way; the version above is preferred because it
touches one line and adds no concept.

**No other file changes.** `Cassette`, `Videorecorder`, `Request`, `Response`, the storages and the
library hooks are untouched.

---

## TEST PLAN

All tests are offline, deterministic, and use only `VCR\Request` objects — no HTTP, no filesystem, no
cassette. They follow the existing style of `tests/Unit/RequestMatcherTest.php`
(namespace `VCR\Tests\Unit`, `final class`, plain `TestCase`).

### `tests/Unit/RequestMatcherTest.php` — added cases

| Test | Stored body | Incoming body | Expected |
| --- | --- | --- | --- |
| `testMatchingJsonBodyIgnoresObjectKeyOrder` | `{"a":1,"b":2}` | `{"b":2,"a":1}` | `true` |
| `testMatchingJsonBodyIgnoresFormatting` | `{"a":1,"b":2}` | `{\n  "a" : 1,\n  "b" : 2\n}` | `true` |
| `testMatchingJsonBodyIgnoresNestedObjectKeyOrder` | `{"o":{"x":1,"y":{"p":true,"q":null}}}` | `{"o":{"y":{"q":null,"p":true},"x":1}}` | `true` |
| `testMatchingJsonBodyRespectsArrayOrder` | `["a","b"]` | `["b","a"]` | `false` |
| `testMatchingJsonBodyRespectsNestedArrayOrder` | `{"m":[{"r":"a"},{"r":"b"}]}` | `{"m":[{"r":"b"},{"r":"a"}]}` | `false` |
| `testMatchingJsonBodyIsStrictAboutScalarTypes` (data provider) | `{"a":1}` | `{"a":"1"}` / `{"a":1.0}` / `{"a":true}` / `{"a":null}` | `false` |
| `testMatchingJsonBodyDistinguishesObjectFromArray` | `{}` | `[]` | `false` |
| `testMatchingJsonBodyDetectsExtraKey` | `{"a":1}` | `{"a":1,"b":2}` | `false` |
| `testMatchingJsonBodyDetectsMissingKey` | `{"a":1,"b":2}` | `{"a":1}` | `false` |
| `testMatchingJsonBodyDetectsArrayLengthChange` | `["a"]` | `["a","b"]` | `false` |
| `testMatchingJsonBodyFallsBackToStringComparisonForInvalidJson` | `{"a":1` | `{"a":1` | `true` |
| `testMatchingJsonBodyFallsBackAndFailsForDifferentInvalidJson` | `not json` | `also not json` | `false` |
| `testMatchingJsonBodyFallsBackWhenOnlyOneSideIsJson` | `{"a":1}` | `plain text` | `false` |
| `testMatchingJsonBodyMatchesTwoEmptyBodies` | *(no body set)* | *(no body set)* | `true` |
| `testMatchingJsonBodyMatchesTwoEmptyStringBodies` | `''` | `''` | `true` |
| `testMatchingJsonBodyDoesNotMatchEmptyAgainstJson` | *(no body)* | `{}` | `false` |
| `testMatchingJsonBodyIgnoresWhitespaceOnlyBody` | `'   '` | `'   '` | `true` (string fallback) |
| `testMatchingJsonBodyComparesLargeIntegersExactly` | `{"id":9223372036854775808}` | `{"id":9223372036854775809}` | `false` |
| `testMatchingJsonBodyTreatsScalarBodyAsString` | `5` | `5.0` | `false` (string fallback) |

### `tests/Unit/ConfigurationTest.php` — added cases

- `testBodyJsonMatcherIsRegistered` — `enableRequestMatchers(['body_json'])` does not throw and
  `getRequestMatchers()` returns exactly one callable.
- `testDefaultMatcherSetOutcomeIsUnchangedByBodyJson` — the regression test for the AND-identity claim:
  for a table of body pairs (identical, reordered-object, reordered-array, invalid JSON, empty), assert
  that `$request->matches($other, $config->getRequestMatchers())` with the *default* configuration
  returns the same boolean as it does with `enableRequestMatchers(['method','url','host','headers','body','post_fields','query_string','soap_operation'])`.

### Existing suite

`tests/Unit/RequestMatcherTest.php`, `tests/Unit/ConfigurationTest.php`, `tests/Unit/CassetteTest.php`,
`tests/Unit/VideorecorderTest.php` and the `tests/Integration/**` suites must pass unmodified. The
regression test above is what makes that claim checkable rather than asserted.

### Quality gates

PHPUnit + PHPStan (no new baseline entries — the helpers are fully typed; `mixed` params are annotated)
+ PHP-CS-Fixer + editorconfig-checker, on the Docker matrix (`workspace80` lowest/highest,
`workspace85` lowest/highest) as required by `CONTRIBUTING.md`.

---

## BACKWARD COMPATIBILITY

**Classification: FULL.**

| Aspect | Impact |
| --- | --- |
| Existing public API | Unchanged. One static method is *added* to `VCR\RequestMatcher`. |
| Existing default behaviour | Unchanged. `body AND body_json ≡ body`, proven above and asserted by a test. |
| Cassette format | Unchanged. This PR touches matching only; nothing is serialised differently. |
| Existing cassettes | Fully replayable, including cassettes recorded by older versions. |
| Existing tests | Pass unmodified. |
| Exception handling | Unchanged. No new exception type, no changed message. |
| Dependencies | None added. `ext-json` is bundled and enabled by default since PHP 8.0 and cannot be disabled; php-vcr's own `Storage\Json` already depends on it unconditionally. |
| PHP versions | Uses `json_decode`, `get_object_vars`, `trim` only — supported across the whole declared range (`^8,<8.2 \|\| >=8.2.9,<8.6`). |
| Subclasses | A user subclass of `RequestMatcher` that already declares a static `matchBodyJson()` with a different signature would fatal. `RequestMatcher` is a bag of static methods never documented as an extension point, and the name is new; risk is negligible but stated for completeness. |

---

## DOCUMENTATION

Behaviour-changing PRs must update `docs/` in the same PR (`CONTRIBUTING.md`).

1. **`docs/reference/request-matchers.md`**
   - "All 8 are enabled by default" → "All 9 are enabled by default".
   - Add `body_json` to the *On this page* nav line, after `body`.
   - New section after `body`:

     ```markdown
     ## `body_json`

     - **Compares:** the request body decoded as JSON, structurally

     Object key order is ignored, array element order is significant, and scalars are compared
     strictly. If either body is not decodable JSON (or is empty), this matcher falls back to the
     same raw-string comparison as [`body`](#body).

     ```php
     // {"model":"x","stream":false}  vs.  {"stream":false,"model":"x"}  -> match
     // ["a","b"]                     vs.  ["b","a"]                     -> no match
     // {"n":1}                       vs.  {"n":"1"}                     -> no match
     ```

     Enabled by default like every other built-in matcher, where it is subsumed by `body`. To get
     order-insensitive JSON matching, replace `body` with `body_json`:

     ```php
     \VCR\VCR::configure()
         ->enableRequestMatchers(['method', 'url', 'host', 'query_string', 'body_json']);
     ```
     ```

2. **`docs/guides/request-matching.md`** — one sentence in the body-matching discussion pointing at
   `body_json` as the answer to "my JSON payload key order changed".

3. **`docs/howto/custom-request-matcher.md`** — no change needed; the custom-matcher recipe stays the
   escape hatch for anything `body_json` does not cover (subset matching, ignoring specific keys).

---

## MAINTAINER OBJECTIONS / ANSWERS

**"Why do we need this? Can't users write it themselves with `addRequestMatcher()`?"**
They can, and many do — badly. A correct implementation has to get five subtle things right:
object-vs-array distinction after `json_decode` (the `{"0":"a"}` / `["a"]` trap that assoc-mode
decoding hides), strict scalar typing, array order significance, the invalid-JSON fallback, and empty
bodies. A userland one-liner that does `json_decode($a, true) == json_decode($b, true)` gets *all five*
wrong: `==` is loose, so `1` matches `"1"` and `0` matches `null`. Shipping the correct version once, in
core, next to `soap_operation` — which is a considerably more domain-specific built-in — is the same
trade-off php-vcr already made.

**"Does this change default behaviour?"**
No, and this is provable rather than a promise: `body_json` is strictly weaker than `body`, they are
ANDed, so `body AND body_json ≡ body`. The PR includes a regression test that runs the full default
matcher set against a table of body pairs and asserts identical results with and without `body_json`.
If a reviewer still objects on principle, the alternative variant (register the name without adding it
to the implicit "all" set) is described in the implementation plan and can be swapped in without
touching the matcher itself.

**"Can this be implemented using the existing extension point?"**
It *is* implemented using the existing extension point — a `callable(Request, Request): bool` registered
in `Configuration::$availableRequestMatchers`, identical in shape to the eight existing matchers. No new
extension point is introduced.

**"Why is this API necessary? Why not a configuration flag on the existing `body` matcher?"**
A flag on `body` would change the meaning of an existing matcher name depending on global state, so a
cassette-sharing team could not tell from `enableRequestMatchers()` what matching is in effect. A
separate name is self-documenting, opt-in by selection rather than by flag, and consistent with how
`query_string` and `post_fields` already split one conceptual "body/params" area into named matchers.

**"Does this add dependencies?"**
No. `ext-json` is always available on PHP 8+ and `Storage\Json` already relies on it unconditionally.

**"Does this change cassette compatibility?"**
No. Nothing about serialisation, the record format, or `Request::toArray()` is touched. Cassettes
recorded before this PR replay identically after it.

**"Does this support old php-vcr versions / all supported PHP versions?"**
It uses only `json_decode`, `json_last_error`, `get_object_vars` and `trim`. `array_is_list` is
deliberately avoided (it would need PHP 8.1 and, worse, would reintroduce the object/array ambiguity).
Works across the entire declared PHP range.

**"Isn't this too large for one PR?"**
It is one matcher: ~70 lines of implementation in one existing file, one line in `Configuration`, one
docs section. Diagnostics and sanitisation are deliberately *not* in this PR.

**"Does this conflict with #446 (matcher objects, 2.0)?"**
No. `body_json` is registered as a plain callback exactly like the eight existing matchers, so whatever
migration #446 applies to those applies to this one unchanged. It adds a matcher; it does not add a
matcher *architecture*.

**"How do we know this matters in practice?"**
See `evidence.md` in this directory: a real test suite replaying JSON API traffic where the client
serialises payloads from associative arrays. No part of that evidence is a dependency of this PR — the
proposal is self-contained php-vcr code.

---

## PRE-SUBMISSION CHECKLIST

- [ ] Open an issue first describing the problem (project convention: PRs reference an issue), then
      submit the PR with `Fixes #<n>`.
- [ ] Conventional Commits, one logical change per commit, every commit green.
- [ ] Run the Docker matrix and list the workspaces in the PR body.
- [ ] Carry over milestone and the `Feature` label from the issue.
