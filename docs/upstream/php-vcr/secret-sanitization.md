# Upstream proposal #2 — Secret sanitization before cassette persistence

**Status:** REJECT — do not submit. **Already solved upstream by an open PR.**
**Superseded by:** [php-vcr/php-vcr#503](https://github.com/php-vcr/php-vcr/issues/503) (issue,
opened 2026-08-12) and [php-vcr/php-vcr#504](https://github.com/php-vcr/php-vcr/pull/504) (PR, open,
authored by the maintainer `higidi`).
**Recommended action:** contribute to #504 as a reviewer, not a competing PR.

---

## Why this proposal is withdrawn

The plan for this phase was to design the smallest idiomatic php-vcr extension point for keeping
credentials out of cassettes. That analysis was carried out in full (below) — and it converges on
exactly the design the maintainer has already implemented and opened as PR #504, three days before this
review. Submitting a second proposal for the same problem would be noise.

What #504 delivers, from its own description:

```php
VCR::configure()->setStorageFactory(
    RedactingStorageFactory::withRules(
        new YamlStorageFactory(),
        RedactionRules::create()
            ->filterSensitiveData('<<API_TOKEN>>', getenv('API_TOKEN'))
            ->postField('password', getenv('TEST_PASSWORD'))
            ->header('Set-Cookie', Scope::RESPONSE)
    )
);
```

- a `RedactingStorage` / `RedactingStorageFactory` decorator that strips secrets on write and restores
  them on read, *below* the matching layer;
- therefore redaction that does not force users to narrow their enabled matchers;
- response redaction, not just request redaction;
- coverage of the two leak channels our own analysis also identified as easy to miss
  (`response.curl_info.request_header`, `request.post_files`);
- an explicit reversibility contract, with `safeRequestMatchers()` / `invalidatedRequestMatchers()`;
- zero changes to `Configuration`, `VCRFactory`, `Videorecorder`, `Cassette`, `Request`, `Response`;
- composability with the encrypted storage decorator from #502 (redact-then-encrypt);
- docs (`docs/howto/custom-redaction-rule.md`) and a public `RedactionRuleInterface`.

That is a strictly better answer than anything proposed here, and it is upstream's own.

---

## The analysis, kept for the record

This section documents what an independent review of the installed php-vcr source concluded *before*
#503/#504 were found. It is retained because it independently validates #504's design and because two
of its findings are worth raising in review.

### Where the recording boundary actually is

`Videorecorder::handleRequest()` (`src/VCR/Videorecorder.php`):

```php
$this->dispatch(new BeforeHttpRequestEvent($request), VCREvents::VCR_BEFORE_HTTP_REQUEST);
$response = $this->client->send($request);
$this->dispatch(new AfterHttpRequestEvent($request, $response), VCREvents::VCR_AFTER_HTTP_REQUEST);

$this->dispatch(new BeforeRecordEvent($request, $response, $this->cassette), VCREvents::VCR_BEFORE_RECORD);
$this->cassette->record($request, $response, $index);
```

`$response` is then returned from `handleRequest()` and handed straight to `CurlHelper::handleOutput()`,
i.e. it *is* the caller's live response. `$request` is the object the library hook keeps in
`CurlHook::$requests[(int) $curlHandle]` for the lifetime of the handle.

### Why `BeforeRecordEvent` is the wrong place — three independent reasons

1. **Responses cannot be redacted at all.** `VCR\Response` has a `final public function __construct()`
   and exposes getters only — no `setHeader()`, no `setBody()`. `BeforeRecordEvent` has no
   `setResponse()`. A listener therefore cannot alter the response half of the recording by any means.
   This matches #503 point 1 exactly.

2. **Mutating the request breaks replay.** `VCR\Request` *is* mutable (`setHeader()`,
   `removeHeader()`, `setBody()`). A listener can mutate it, and `Cassette::record()` then serialises
   the mutated object — that is the workaround php-vcr's own
   `docs/howto/filter-sensitive-data.md` documents. But the cassette now holds `[REDACTED]` while every
   subsequent run presents the *real* header to `RequestMatcher::matchHeaders()`, which compares full
   header arrays. The `headers`, `body`, `post_fields` and `query_string` matchers all stop matching.
   This matches #503 point 2 exactly. Any sanitization design that is not symmetric — applied on write
   *and* reversed or compensated on read — is broken by construction.

3. **Mutating the request mutates live shared state.** The object a listener would mutate is the same
   instance `CurlHook` holds in `self::$requests[(int) $curlHandle]`. The HTTP call has already
   happened, so the wire is unaffected and the stated security invariant (live transport ≠ persisted
   cassette) is not violated on the request side — but the mutation is visible to anything else holding
   that handle, and `Cassette::record()` calls `hasResponse()` internally, re-running the matchers
   against the now-mutated request. Sanitisation must operate on a copy, not on the live object.

### Where sanitization belongs

Below `Cassette`, at the `Storage` boundary. `Cassette::record()` calls
`$this->storage->storeRecording([...])` with a plain array, and `Cassette::playback()` iterates
`$this->storage` and rebuilds `Request::fromArray()` / `Response::fromArray()`. A decorator implementing
`Storage` that transforms the array on write and inverts the transform on read:

- sees both halves of the recording, so responses are covered;
- sits *below* matching, so `Cassette::playback()` matches against restored values and no matcher has
  to be disabled;
- needs no change to `Request`, `Response`, `Cassette`, `Videorecorder` or the events;
- composes with the existing `EncryptedStorage` decorator;
- is exactly the shape `StorageFactoryInterface` (already on master) was introduced for.

This is #504's design. Independent convergence on it is the strongest thing this analysis can say.

### Two leak channels

Both were identified here and both are already covered by #504 — noting them only to confirm they are
real in the installed source:

- **`response.curl_info.request_header`.** `Response::toArray()` serialises `curl_info` verbatim, and
  curl populates `request_header` with the raw outbound request line *including* `Authorization`. So
  redacting `request.headers` alone leaves a verbatim copy of the secret in the response half of the
  same recording.
- **`request.post_files`.** `Request::toArray()` emits `post_files` as its own key; a redaction pass
  that walks `headers` and `body` misses it.

---

## What to contribute to #504 instead

1. **Review the reversibility contract against the invariant this project needs.** Our security
   requirement is "the persisted cassette differs from the live exchange; the live exchange is never
   altered". #504's `filterSensitiveData()` is described as *always reversible*, which means the real
   value is reconstructed in memory on read. Worth confirming in review that the restored value never
   reaches a file, only the matcher.

2. **Offer the LLM-provider test case as an integration fixture.** A recorded exchange where the
   provider echoes part of the credential back in the response body exercises the response-side
   redaction path and the `curl_info.request_header` channel simultaneously. See `evidence.md`.

3. **Do not open a competing PR.** If #504 stalls, the correct escalation is a comment on #503, not a
   parallel implementation.

---

## Decision

| Criterion | Verdict |
| --- | --- |
| Generic | Yes |
| Minimal | Yes |
| Backward compatible | Yes (as designed in #504) |
| Evidence | Yes |
| Upstream ready | **Already upstream** |
| Recommendation | **REJECT (duplicate of #504) — review, don't resubmit** |
