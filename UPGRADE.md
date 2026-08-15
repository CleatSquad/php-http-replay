# Migration Guide v1.x → v2.3.0

This document describes the changes and steps required to upgrade your application or test suite from `cleatsquad/php-http-replay` v1.x to v2.3.0.

## Breaking Changes Summary

1. **Default Cassette Schema Version 2**:
   - `JsonCassetteStore` creates cassettes with `"version": 2`.
   - Existing `version: 1` cassettes are loaded automatically via backward-compatibility reader. No manual file conversion is strictly required.

2. **Exchange Selection Architecture**:
   - `HttpReplayEngine` now delegates exchange matching candidates to `ExchangeSelectorInterface`.
   - Default behavior remains strictly `ExecutionMatchingMode::Sequential`.

3. **MatchResult Export**:
   - Added `$result->toArray()` for machine-readable JSON/CI integrations alongside `$result->toCliString()`.

## Upgrading Steps

1. Update your `composer.json`:
   ```json
   {
       "require-dev": {
           "cleatsquad/php-http-replay": "^2.3.0"
       }
   }
   ```
2. Run `composer update cleatsquad/php-http-replay`.
3. Existing cassettes will continue to pass. Any newly recorded cassettes will use Schema Version 2 with SHA-256 integrity checksums.
