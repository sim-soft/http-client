# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 2.2.4 and earlier were reconstructed from commit history after the
fact, so they summarise each release rather than list every change.

## [Unreleased]

### Security

- **Header injection.** Header names and values are now validated: names must
  be valid RFC 7230 tokens, and values may not contain CR, LF or NUL bytes.
  Previously these were interpolated into header lines unchecked and passed
  straight to cURL, so a caller-supplied value forwarded into a header — a
  tenant id, locale or correlation id taken from user input — could terminate
  the line early and forge additional headers on the wire. `withHeader()`,
  `withHeaders()` and `withBearerToken()` now throw `InvalidArgumentException`,
  and `buildFormattedHeaders()` re-validates as a backstop for headers that do
  not arrive through those methods, such as a content type given to
  `withBody()`. URLs were never affected — cURL rejects CRLF in a URL.

- **OAuth2 token file permissions.** `FileStorage` now creates token files
  `0600` and enforces `0700` on the storage directory even when it already
  exists. Files were previously written at the default umask, and the `0700`
  on `mkdir()` was skipped entirely for a pre-existing directory, so on a
  shared host — where the default location is the world-writable system temp
  directory — live access tokens could be readable by other local users.
  Permissions are applied before the token is written, so the secret is never
  briefly exposed. `chmod` is skipped on Windows, where POSIX modes do not
  apply.

- **PSR-18 credential disclosure to third-party hosts.** `sendRequest()`
  redirected the base URL at the PSR-7 target while leaving connection-scoped
  headers in place, so a client configured with `withBearerToken()` and then
  handed to a PSR-18-consuming SDK sent its token to whatever host that SDK
  addressed. A token is now withheld when the request targets an origin other
  than the client's base URL, mirroring cURL's handling of `Authorization`
  across a cross-host redirect. An `Authorization` header set on the PSR-7
  request itself is unaffected, and a client with no base URL has no origin to
  compare against.

### Fixed

- **PSR-18 no longer clobbers client configuration.** `sendRequest()` left the
  base URL pointing at the PSR-7 target after it returned, so a client used
  through both APIs — the pattern documented in `docs/PSR18.md` — sent every
  subsequent fluent request to the last PSR-18 host. Base URL and
  connection-scoped headers are now restored once the send completes.

- **Bearer tokens no longer vanish after the first request.** `flush()` cleared
  all headers after every request while the base URL survived, so a client
  configured once and reused — the pattern shown in the README — sent the first
  request authenticated and every subsequent one without a token. Tokens set
  with `withBearerToken()` are now connection-scoped and apply to every request
  made through the client. Headers set with `withHeader()` remain per-request,
  so one-off values such as `Idempotency-Key` still do not leak into the next
  request, and a per-request `Authorization` header takes precedence over the
  token.

- **Request-scoped cURL options leaked between requests.** `flush()` reset some
  state but left sink, upload and body options set, so a client reused after a
  download stayed in sink mode and returned an unreadable body, and a client
  reused after a stream body turned the next request into an upload. A
  caller-provided sink resource was also never detached. Connection-scoped
  configuration — TLS verification, redirect policy, `withOptions()` and retry
  settings — is deliberately preserved.

### Added

- `withoutBearerToken()` removes a connection-scoped token from a client.
- CI covering PHP 8.1 through 8.4, a lowest-dependency run, PHPStan level 8,
  PHPMD and PHPCS.
- This changelog and a security policy.

### Changed

- `withHeader()` and `withHeaders()` had inaccurate `@param` annotations
  (`array<string, mixed>` where a list is also accepted); corrected.

## [2.2.4] - 2026-06-24

### Added

- OAuth2 clients accept customised token and refresh parameters.
- OAuth2 supports disabling the token cache and retrieving the token data
  object directly.
- Additional information can be attached to `TokenData`.
- OAuth2 requests accept a timeout.

### Changed

- Expanded OAuth2 documentation.

## [2.2.3] - 2026-05-24

### Changed

- Documentation moved to GitHub Pages; added the full documentation link.

## [2.2.2] - 2026-05-19

### Fixed

- Bug fixes from a code review pass, and improved tutorials.

## [2.2.1] - 2026-05-10

### Added

- `HttpPool` gained error handling, retries and an `onProgress()` callback.

## [2.2.0] - 2026-05-08

### Added

- `HttpPool` for concurrent requests.

## [2.1.0] - 2026-05-07

### Added

- OAuth2 client.
- Full unit test suite covering the basic functionality.

### Changed

- Refactored for PHPMD compliance.

## [2.0.0] - 2026-04-25

### Added

- PSR-18 support.

### Changed

- Major rewrite. Custom OAuth2 clients refactored for reusability.

## [1.0.3] - 2026-01-15

### Added

- Macros on `HttpClient`.
- Descriptive status check methods on `Response`.
- Configurable wait between retry attempts.

### Fixed

- Retry count validation.

## [1.0.2] - 2025-08-18

### Fixed

- `withOptions()` merged options incorrectly.

## [1.0.1] - 2025-06-29

### Added

- `reset()` and `put()` methods.
- Unit tests.

### Changed

- `post()` sends form data by default.
- Improved request re-attempt behaviour.

## [1.0.0] - 2025-06-29

Initial release.

[Unreleased]: https://github.com/sim-soft/http-client/compare/2.2.4...HEAD
[2.2.4]: https://github.com/sim-soft/http-client/compare/2.2.3...2.2.4
[2.2.3]: https://github.com/sim-soft/http-client/compare/2.2.2...2.2.3
[2.2.2]: https://github.com/sim-soft/http-client/compare/2.2.1...2.2.2
[2.2.1]: https://github.com/sim-soft/http-client/compare/2.2.0...2.2.1
[2.2.0]: https://github.com/sim-soft/http-client/compare/2.1.0...2.2.0
[2.1.0]: https://github.com/sim-soft/http-client/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/sim-soft/http-client/compare/1.0.3...2.0.0
[1.0.3]: https://github.com/sim-soft/http-client/compare/1.0.2...1.0.3
[1.0.2]: https://github.com/sim-soft/http-client/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/sim-soft/http-client/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/sim-soft/http-client/releases/tag/1.0.0
