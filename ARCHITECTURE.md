# Architecture v2.3.0

`php-http-replay` v2.3.0 is a zero-dependency, deterministic HTTP request replay engine built on PSR-7 and PSR-18.

## Pipeline Flow

```
Request Interception (HttpReplayEngine)
        ↓
Exchange Selection (ExchangeSelectorInterface)
        │ ├── SequentialExchangeSelector (Default O(1))
        │ └── UnorderedExchangeSelector (OPT-IN O(N))
        ↓
Semantic Request Matching (RequestMatcherInterface / DefaultRequestMatcher)
        ↓
Sanitization (SanitizerInterface / DefaultSanitizer)
        ↓
Storage & Integrity (CassetteStoreInterface / JsonCassetteStore v2)
        ↓
Audit Stats (ReplayStats)
```

## Extensions Points for Future Satellite Packages
- `cleatsquad/php-http-replay-phpunit`
- `cleatsquad/php-http-replay-pest`
- `cleatsquad/php-http-replay-vcr`
