# Upstream proposal #4 — PSR-18 / Guzzle integration

**Status:** RFC ONLY — **do not submit.**
**Recommended action:** keep as a future RFC; the need is already met outside php-vcr.

---

## The question

Should php-vcr gain a PSR-18 `ClientInterface` decorator (and/or a Guzzle handler/middleware) so that
replay can be wired explicitly into an application's HTTP client, instead of relying on global
interception?

## Verdict

No — not as a php-vcr PR. The proposal fails the test this review applies to every item: *"would a
php-vcr maintainer accept this even if the proposing package did not exist?"*

---

## Architectural compatibility

php-vcr's interception model is not an implementation detail it would like to escape — it is the
product. From the installed source:

- `LibraryHooks\CurlHook`, `LibraryHooks\StreamWrapperHook`, `LibraryHooks\SoapHook` intercept at the
  *function* level (`curl_exec`, stream wrappers, `SoapClient`).
- `Util\StreamProcessor` + `CodeTransform\CurlCodeTransform` rewrite loaded PHP source so that
  `curl_exec()` calls resolve to the hook.
- `Configuration::$blackList` exists specifically to keep that rewriting from recursing into php-vcr
  itself.

The entire design exists so that **unmodified third-party code** — code you cannot inject a client
into — is recorded. The README's headline claim is that you disable all HTTP requests you did not
explicitly allow, everywhere, without touching the code under test.

A PSR-18 decorator inverts that: it records only what is routed through the object you injected.
Anything else — a library that news up its own Guzzle client, a `file_get_contents`, a SOAP call — is
silently *not* recorded. Two coexisting models with different coverage guarantees in one package is a
support burden, not a feature: "why wasn't this request recorded?" becomes a question whose answer
depends on which mode the user thinks they are in.

## Guzzle is already covered

Guzzle 7's default transport is `curl_exec` / `curl_multi_exec`, both of which `CurlHook` intercepts —
including `curlMultiExec`, which is implemented in the hook. php-vcr ships
`tests/Integration/Guzzle` for exactly this. A PSR-18 or handler-level integration would add a second
path to something already working, with no new capability for existing users.

## Dependency impact

A real PSR-18 integration needs `psr/http-client` and, to build the messages, `psr/http-factory` plus a
PSR-7 implementation, or a mapping layer between PSR-7 and `VCR\Request` / `VCR\Response`. Those two
classes are not PSR-7 and are not going to become PSR-7 in the 1.x line: `Response` has a
`final public __construct` and no setters; `Request` carries `curlOptions`, `postFiles` and `postFields`
that have no PSR-7 equivalent. The mapping layer would be lossy in both directions (curl info,
post_files) and would need its own test matrix. Even as an optional `suggest`, it is a permanent
maintenance surface for a package whose current `require` is deliberately small.

## Maintenance cost vs. usefulness

| | Global interception (today) | PSR-18 decorator |
| --- | --- | --- |
| Covers third-party code | Yes | Only if injected |
| Covers `file_get_contents` / SOAP | Yes | No |
| Requires app changes | No | Yes |
| New deps | None | PSR-7/17/18 + mapping |
| Existing users benefit | — | No |

The last row is decisive. This would be new API serving a use case php-vcr's existing users do not
have, maintained by the same volunteers.

## Where this belongs instead

In a separate package. A PSR-18 replay client is a coherent, useful thing — it is simply a *different*
library with a different contract, and it is better served by a package built on PSR-7 from the start
than by a mapping layer bolted onto `VCR\Request`. The existence of such a package outside php-vcr is
evidence that the boundary is in the right place, not an argument for merging it in.

## If it were ever revisited

The only version worth proposing later would be:

- a *separate* Composer package under the php-vcr organisation (e.g. `php-vcr/psr18-client`),
- depending on `php-vcr/php-vcr` rather than living inside it,
- reusing `Cassette` and the storages, not reimplementing them,
- with an explicit statement in its README that it does **not** provide php-vcr's global "no unrecorded
  request escapes" guarantee.

That is a conversation to open as an issue, not a PR, and only if there is user demand on the tracker.
There is currently none: no open or closed issue in `php-vcr/php-vcr` mentions PSR-18 or a Guzzle
handler integration.

---

## Decision

| Criterion | Verdict |
| --- | --- |
| Generic | Yes |
| Minimal | No — new deps, new mapping layer, second interception model |
| Backward compatible | Would be, but irrelevant |
| Evidence | Not from php-vcr's user base |
| Upstream ready | No |
| Recommendation | **RFC ONLY — do not submit** |
