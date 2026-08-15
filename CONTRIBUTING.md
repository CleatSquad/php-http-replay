# Contributing

Contributions are welcome — bug reports, documentation, and code alike.

## Getting started

```bash
git clone https://github.com/CleatSquad/php-http-replay.git
cd php-http-replay
composer install
```

## Before opening a pull request

```bash
composer test      # PHPUnit
composer analyse   # PHPStan, max level, must report no error
```

## Guidelines

- Follow PSR-12. Match the style of the surrounding code.
- Every behaviour change needs a test. Bug fixes need a test that fails before
  the fix.
- Keep the public API small. A new public method is a long-term commitment.
- The core depends on PSR interfaces only. Guzzle and Symfony YAML stay
  confined to `src/Integration/` and `src/PhpVcr/`, and stay optional at
  runtime. Pull requests moving a concrete HTTP client into the core need to
  make a strong case.
- Cassettes are written to disk and often committed. Anything that could let an
  unredacted credential reach a cassette file is a bug, not a feature request.
- Update the README when public behaviour changes, and add a CHANGELOG entry.

## Backward compatibility

Anything under `src/` that is `public` is part of the public API and follows
[Semantic Versioning](https://semver.org), except `src/Internal/`, which is
explicitly excluded. Breaking the public API requires a major release, so
prefer additive changes.

## Cassette format

The stored JSON format carries a `version` field. Changing the shape of a
cassette means bumping that version and keeping the reader able to load the
previous one.

## Commit messages

Short imperative subject line, explaining what changes and why.
