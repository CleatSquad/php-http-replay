# Upstream proposal #3 — Structured request mismatch diagnostics

**Status:** NEEDS REVISION — **issue first, no PR yet.**
**Blocking context:** [php-vcr/php-vcr#94](https://github.com/php-vcr/php-vcr/issues/94) was closed by
the maintainer on 2026-06-07 having explicitly considered and rejected the obvious design.
**Recommended action:** open a new issue implementing the maintainer's own stated escape hatch, and only
write code once it gets a positive signal.

---

## Why this is not "ready to submit"

Issue #94 ("indicate why cassette does not have necessary response") ran for eleven years and was closed
with this reasoning:

> What we considered but decided against: heuristically picking the "closest" recording and showing
> per-matcher PASS/FAIL diffs. Sounds nice in your single-recording case, but on cassettes with N
> recordings it turns into guessing — we'd pick the recording with the most matchers passing, which
> isn't necessarily the one you intended. Pointing users at the wrong recording felt worse than
> pointing them at none.
>
> Closing this for now. If you (or anyone reading) has a concrete proposal that doesn't rely on
> guessing — e.g. only-when-single-recording diagnostics, or a separate inspector command — please open
> a new issue, happy to look at it.

The version of this proposal drafted in the previous phase — report the closest recording with a
per-field diff and a JSON path — is precisely the design that was rejected. Submitting it as a PR would
be submitting a decision the maintainer already made, in reverse. That is why this is marked NEEDS
REVISION rather than READY TO SUBMIT.

It is also worth noting that the maintainer left the door open and named two acceptable shapes. Both are
achievable. The revision below takes the first one.

---

## Current behaviour, from the installed source

`Videorecorder::handleRequest()` throws a bare `\LogicException`:

```php
throw new \LogicException(\sprintf(
    "The request does not match a previously recorded request and the 'mode' is set to '%s'. "
    ."If you want to send the request anyway, make sure your 'mode' is set to 'new_episodes'. "
    .'Please see http://php-vcr.github.io/documentation/configuration/#record-modes.'
    ."\nCassette: %s \n Request: %s",
    $this->config->getMode(), $this->cassette->getName(), print_r($request->toArray(), true)
));
```

Facts that constrain any design:

- There is **no** `CannotMatchRequestException`. `VCRException` exists but extends
  `Assert\InvalidArgumentException` and is used only for `LIBRARY_HOOK_DISABLED` / `REQUEST_ERROR`. Any
  new exception type would have to extend `\LogicException` to keep existing `catch` blocks working.
- The exception already dumps the *incoming* request in full. What is missing is the comparison against
  what is on the cassette.
- `Cassette::playback()` returns `?Response`. It has no channel for "why not".
- `Configuration::getRequestMatchers()` returns `array_values(...)` — **the matcher names are
  discarded**. Any per-matcher diagnostic needs the names back.
- `Request::matches()` loops matchers and returns `false` on the first failure. It is a `bool` API and
  issue #446 reserves changing that for 2.0.

---

## Revised proposal (to be opened as an issue, not a PR)

Split into two increments. Only increment A should be proposed initially.

### Increment A — unambiguous diagnostics only

Report *which enabled matchers failed*, and only when there is no guessing involved:

- the cassette contains **exactly one** recording at the requested index → compare against it and name
  the failing matchers;
- the cassette contains more than one candidate → say so, report the candidate count, and add nothing
  else. No "closest match", no scoring, no heuristic.

Resulting message, single-candidate case:

```
The request does not match a previously recorded request and the 'mode' is set to 'none'.
Cassette: llm_chat (1 recording at index 0)
Matchers that failed: headers, body
Request: <existing full dump>
```

Multi-candidate case:

```
The request does not match any of the 14 recordings at index 0 in cassette 'llm_chat'.
Matchers enabled: method, url, host, headers, body, post_fields, query_string, soap_operation
Request: <existing full dump>
```

Minimal implementation surface:

1. `Configuration::getRequestMatcherNames(): array` — additive getter returning the enabled matcher
   names in the same order as `getRequestMatchers()`.
2. `Cassette::explainMismatch(Request $request, int $index): string` (or a small value object) — walks
   the storage, counts candidates at `$index`, and in the single-candidate case runs each named matcher
   individually.
3. `Videorecorder::handleRequest()` appends the result to the existing message.

No matcher signature changes. No new exception class. No cassette format change. Existing `catch
(\LogicException)` blocks keep working; only the message text grows.

**Known cost, to be stated in the issue:** the diagnostic re-iterates the storage a second time on the
failure path only. That path already throws, so the cost is paid once per failing test.

### Increment B — JSON path diffs

The `body.messages.0.content` style of output (expected `hello`, actual `goodbye`) is genuinely more
useful than "body failed", but it is a *second* feature:

- it only makes sense for JSON bodies, which means it is coupled to proposal #1 (`body_json`) landing
  first;
- it needs a JSON structural differ that the boolean matcher deliberately does not contain;
- it multiplies the size of the diff on the exact PR where the maintainer is already sceptical.

Increment B should not be mentioned in the initial issue beyond a single line noting it as possible
follow-up.

### Rejected alternative

`MatchResult` / `Difference` value objects returned from matchers — i.e. turning the boolean matcher API
into a structured one. This is off the table: it is a BC break on a public extension point, and #446
already assigns matcher-API changes to 2.0. Reproducing that model from another library would also be
exactly the kind of foreign architecture this review is supposed to avoid.

---

## Test plan (for increment A, if it is greenlit)

`tests/Unit/CassetteTest.php`:

- single recording, method differs → diagnostic names `method`
- single recording, URL path differs → names `url`
- single recording, header differs → names `headers`
- single recording, body differs → names `body`
- single recording, several matchers fail → names all of them, in enabled order
- single recording, matching request → no diagnostic produced
- two recordings at the same index → diagnostic reports the candidate count and no per-matcher detail
- empty cassette → diagnostic reports zero candidates

`tests/Unit/VideorecorderTest.php`:

- `MODE_NONE` with an unmatched request still throws `\LogicException` (type unchanged)
- the thrown message contains both the existing text and the new diagnostic section

All offline, all using in-memory / vfsStream storage as the existing `CassetteTest` already does.

---

## Backward compatibility

**Classification: MINOR CONCERN.**

| Aspect | Impact |
| --- | --- |
| Exception type | Unchanged (`\LogicException`). Existing catch blocks valid. |
| Exception message | **Changed** (extended). Any test asserting the exact message string breaks. This is the one real concern and must be called out in the issue. |
| Public API | One additive `Configuration` getter, one additive `Cassette` method. |
| Matcher API | Unchanged. |
| Cassette format | Unchanged. |
| Dependencies | None. |
| Performance | Failure path only. |

---

## Maintainer objections / answers

**"We already closed #94. Why are we here again?"**
Because the closure explicitly invited a proposal that does not guess, and named single-recording
diagnostics as an acceptable shape. This is that proposal, and it declines to do the thing that was
rejected.

**"Doesn't this still guess when there are many recordings?"**
No — that is the whole point of the design. With more than one candidate it reports the candidate count
and stops. It never scores, never ranks, never nominates a recording.

**"Isn't the existing message good enough? It already dumps the request."**
It dumps one side. The user still has to open the cassette and compare by eye — and the original
reporter's problem (a `Content-Length` mismatch) is exactly the kind that survives eyeballing. Naming
the failing matcher turns an hour into a second.

**"Why does `Configuration` need a new getter?"**
`getRequestMatchers()` throws the names away with `array_values()`. Without names the diagnostic can
only say "some matcher failed". The getter is four lines and additive.

**"Does this change the matcher API?"**
No. Matchers remain `callable(Request, Request): bool`. Nothing about #446's 2.0 plan is pre-empted.

**"Is it too large?"**
Increment A is roughly 60 lines across three files plus tests. Increment B is deliberately excluded.

---

## Decision

| Criterion | Verdict |
| --- | --- |
| Generic | Yes |
| Minimal | Yes, after splitting off increment B |
| Backward compatible | MINOR CONCERN (exception message text changes) |
| Evidence | Yes |
| Upstream ready | No — needs maintainer buy-in on the revised shape first |
| Recommendation | **NEEDS REVISION → open an issue referencing #94, wait for a signal, then PR** |
