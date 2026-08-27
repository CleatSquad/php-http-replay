# Evidence — real-world validation

This file records the observations that motivated the upstream proposals in this directory. It exists so
that the PR bodies can cite a concrete, reproduced failure mode instead of a hypothetical one.

**It is not part of any PR.** No class, package or API named here is a dependency of any proposal. Every
proposal in this directory is written as self-contained php-vcr code. When quoting from this file in an
upstream issue or PR, quote the *behaviour*, never the tooling.

---

## 1. JSON object key order breaks replay

**Setup.** A test suite replays HTTP traffic to a JSON API. The client builds request bodies from PHP
associative arrays and serialises them with `json_encode`. The cassette was recorded before a refactor
that reordered two fields in the array literal.

**Observed with raw-string body matching.**

Recorded body:

```json
{"model":"gpt-5","prompt":"Tell me a joke"}
```

Body sent after the refactor:

```json
{"prompt":"Tell me a joke","model":"gpt-5"}
```

The two are identical to the server. With `RequestMatcher::matchBody()` they are two different strings,
so `Cassette::playback()` returns `null`. In `MODE_NONE` the test fails with the `LogicException` from
`Videorecorder::handleRequest()`; in `MODE_NEW_EPISODES` a live HTTP call is issued and a near-duplicate
recording is appended.

**Observed with structural JSON comparison.** The two bodies match. Independently:

```
["a","b"]  vs  ["b","a"]   -> no match   (array order stays significant)
{"n":1}    vs  {"n":"1"}   -> no match   (scalars stay strictly typed)
```

This is the exact behaviour proposal #1 (`body_json`) specifies.

**What this does *not* prove.** Nothing about this failure is specific to any API or vendor. It reproduces
with any JSON-over-HTTP client whose serialisation order is not pinned. That genericity is the reason
the proposal is considered upstream-worthy at all.

---

## 2. Precise mismatch location

**Setup.** Same suite, a body that differs deep inside a nested structure.

**Observed today.** The failure message names the cassette, the record mode, and dumps the incoming
request via `print_r($request->toArray(), true)`. It does not say which matcher failed or where the two
bodies diverge. Locating the difference means opening the cassette and comparing two multi-kilobyte JSON
blobs by eye.

**Observed with structural diagnostics.** The failure identifies the diverging path directly:

```
body.messages.0.content
  expected: hello
  actual:   goodbye
```

**Caveat, and why proposal #3 is not a PR.** This output was produced against a single known recording.
On a cassette holding many recordings, producing it requires choosing *which* recording to diff against —
and that choice is the heuristic the php-vcr maintainer explicitly rejected when closing issue #94. The
evidence here supports the *value* of precise diagnostics; it does not answer the objection. Proposal #3
is therefore scoped down to the unambiguous case and routed through a new issue first.

---

## 3. Secrets in cassettes, without touching the live exchange

**Setup.** An integration test asserts three things simultaneously about a recorded exchange carrying a
bearer token in the request headers, a secret in the request JSON body, and a session cookie in the
response headers.

**Observed.**

| Channel | Live exchange | Persisted cassette |
| --- | --- | --- |
| Request `Authorization` | `Bearer REAL_SECRET_TOKEN` | `Bearer [REDACTED]` |
| Request body `secret` | `REAL_SENSITIVE_KEY` | `[REDACTED]` |
| Response `Set-Cookie` | `REAL_SESSION` | `[REDACTED]` |

The transport received the unmodified request; the caller received the unmodified response. Only the
serialised recording differs.

**Why this is not proposal material.** php-vcr already has an open issue (#503) and an open PR (#504,
by the maintainer) covering exactly this, with a design that also handles two leak channels this
evidence does not exercise: `response.curl_info.request_header`, where curl stores the raw outbound
header block including `Authorization`, and `request.post_files`. The useful contribution is the third
row of that table — response-side redaction, which no `VCR_BEFORE_RECORD` listener can currently
perform, since `VCR\Response` has a `final` constructor and no setters and `BeforeRecordEvent` has no
`setResponse()`. Offering this as an integration fixture on #504 is the recommended action.

---

## 4. Round-trip compatibility with existing cassettes

Existing php-vcr YAML cassettes were read and replayed unchanged during this validation. This matters
for the compatibility claims in proposal #1: nothing in that proposal touches serialisation, and
cassettes recorded by any php-vcr version replay identically before and after it.
