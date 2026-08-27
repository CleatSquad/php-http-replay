# php-vcr upstream contribution review

Analysis of whether improvements validated locally can be contributed to
[`php-vcr/php-vcr`](https://github.com/php-vcr/php-vcr) as small, generic, backward-compatible PRs.

**Status.** Proposal #1 has been submitted as a **draft** PR:
[php-vcr/php-vcr#505](https://github.com/php-vcr/php-vcr/pull/505) (2026-08-15, from
`mohaelmrabet:feat/json-body-matcher`). Proposals #2, #3 and #4 remain unsubmitted by design — see their
recommendations below.

## Source of truth

- Installed copy: `vendor/php-vcr/php-vcr` — `dev-feat/441-symfony-8`, ref `6fcdbb5`
- Upstream `master`, read directly for the files that have moved since that ref
  (`Storage/` now has `StorageFactoryInterface`, `EncryptedStorage`, `PurgeableStorage`; `docs/` is now
  in-repo since #489)
- `RequestMatcher`, `Configuration`, `Cassette`, `Videorecorder`, `Request`, `Response` and the events
  are identical on both — all proposals target `master`.

## Decision matrix

| Proposal | Generic | Minimal | Backward compatible | Evidence | Upstream ready | Recommendation |
| --- | --- | --- | --- | --- | --- | --- |
| [#1 Semantic JSON matcher (`body_json`)](semantic-json-matcher.md) | Yes | Yes — 1 method + 1 line | FULL | Yes | Yes | **SUBMITTED — draft [#505](https://github.com/php-vcr/php-vcr/pull/505)** |
| [#2 Secret sanitization](secret-sanitization.md) | Yes | Yes | FULL | Yes | Already upstream | **REJECT — duplicate of open PR #504** |
| [#3 Structured mismatch diagnostics](structured-mismatch-diagnostics.md) | Yes | After splitting | MINOR CONCERN (message text) | Yes | No | **NEEDS REVISION — issue first** |
| [#4 PSR-18 / Guzzle](psr18-guzzle-future.md) | Yes | No | n/a | Not from php-vcr users | No | **RFC ONLY — do not submit** |

## Recommended order

1. **Proposal #1** — ✅ done, draft [#505](https://github.com/php-vcr/php-vcr/pull/505). Opened without a
   linked issue, with an explicit offer in the PR body to open one first if the maintainer prefers to
   discuss before reviewing code. Next step: mark ready for review once CI is green and the maintainer
   has answered the one open design question (whether `body_json` may join the implicit default set, or
   should be registered outside it).
2. **Proposal #2** — review [#504](https://github.com/php-vcr/php-vcr/pull/504), offer the response-side
   redaction fixture from [`evidence.md`](evidence.md). Do not open a competing PR.
3. **Proposal #3** — only after #1 has landed and #504 has settled. Open a new issue referencing
   [#94](https://github.com/php-vcr/php-vcr/issues/94), implementing the maintainer's own "no guessing"
   constraint. PR only on a positive signal.
4. **Proposal #4** — no action.

## Upstream context that shaped these decisions

| Ref | What it is | Effect here |
| --- | --- | --- |
| [#503](https://github.com/php-vcr/php-vcr/issues/503) | Issue: redaction breaks replay, responses can't be redacted | Makes #2 a duplicate |
| [#504](https://github.com/php-vcr/php-vcr/pull/504) | Open PR by the maintainer: `RedactingStorage` decorator | Supersedes #2 entirely |
| [#502](https://github.com/php-vcr/php-vcr/pull/502) | Merged: encrypted storage decorator | Establishes the storage-decorator pattern |
| [#94](https://github.com/php-vcr/php-vcr/issues/94) | Closed 2026-06-07: closest-match diffs rejected as guessing | Forces #3 to be rescoped and re-proposed as an issue |
| [#446](https://github.com/php-vcr/php-vcr/issues/446) | Matcher objects / cassette scope, targeted at 2.0 | #1 stays on the callback API; #3 must not touch the matcher API |
| [#489](https://github.com/php-vcr/php-vcr/pull/489) | In-repo `docs/` | Every proposal names its exact doc files |

## Contribution conventions (`.github/PULL_REQUEST_TEMPLATE.md`, `CONTRIBUTING.md`)

- PR body opens with the Q/A table (Type, Fixes, BC break, Deprecation, New dependency, Docs updated,
  License).
- Behaviour changes must update `docs/` in the same PR.
- Conventional Commits, one logical change per commit, every commit green.
- All checks green: PHPUnit + PHPStan + PHP-CS-Fixer + editorconfig-checker.
- Verify on the Docker matrix and list the workspaces actually run.
- No BC breaks outside a major release.

## Files

- [`semantic-json-matcher.md`](semantic-json-matcher.md) — proposal #1, PR-ready
- [`secret-sanitization.md`](secret-sanitization.md) — proposal #2, withdrawn, analysis retained
- [`structured-mismatch-diagnostics.md`](structured-mismatch-diagnostics.md) — proposal #3, rescoped
- [`psr18-guzzle-future.md`](psr18-guzzle-future.md) — proposal #4, RFC only
- [`evidence.md`](evidence.md) — validation observations, not part of any PR
