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

- **OAuth2 token disclosure between end users.** Cached tokens were keyed on the
  client ID alone, so every caller sharing a storage backend read the same
  entry. Two users of the same application were served each other's tokens, and
  a sandbox client could read a production token — or a `read`-scoped client a
  `write`-scoped one. The key now covers the client ID, the subject, the active
  token endpoint and the requested scope. Bind a client to an end user with the
  new `forSubject()`; a shared backend without it remains unsafe for
  user-delegated tokens.

- **OAuth2 privilege substitution after a user's token expired.** When a token
  obtained through the authorization code flow expired and could not be
  refreshed, the client fell back to a `client_credentials` request and stored
  the application's own token under the user's key. Every later call then acted
  with the application's authority while appearing to act with the user's. A
  code exchange now records its grant in the token's metadata, a subject-bound
  client will not acquire a token unattended, and both cases raise instead of
  substituting.

- **OAuth2 single-use CSRF state.** `validateState()` compared the stored state
  with `!==` and removed it only after a successful exchange, so a state could
  be replayed and the comparison leaked timing. The state is now consumed before
  it is compared, and compared with `hash_equals()`. State and PKCE verifier are
  also stored per composed key, so concurrent authorization flows no longer
  overwrite one another — previously a second user starting a flow made the
  first user's callback fail as a suspected CSRF attack.

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

- **A corrupt cached token no longer bricks an OAuth2 client permanently.** An
  unreadable storage entry — truncated, evicted mid-write, hand-edited — made
  `unserialize()` emit a warning and return `false`, which was then treated as a
  cached token; the entry was never replaced, so every subsequent call failed
  for as long as the file existed. Under a framework that promotes warnings to
  exceptions the failure escaped as an uncaught `ErrorException`. Anything that
  is not a `TokenData` is now discarded along with its storage entry, and
  `unserialize()` is restricted to that one class.

- **Token endpoint responses without a token are rejected.** A 2xx response was
  taken as success without checking its body, so a provider returning an
  RFC 6749 §5.2 error with a 200 — or a 200 with no `access_token` at all —
  produced an empty credential that was cached and then sent as `Bearer `,
  turning a diagnosable configuration fault into 401s from the resource server.
  Both are now reported, with the provider's `error` and `error_description`
  when present.

- **Multipart requests were sent form-encoded.** `withMultipart()` set the
  content type only when called a second time, so the documented single call —
  and `post($url, $array)`, which routes through it — sent
  `application/x-www-form-urlencoded` with a `http_build_query()` body. An
  endpoint expecting a multipart upload rejected it, and a file attached
  alongside the fields could not be sent at all.

- **Multiple files attached under one name arrived unusable.** `attach()` sent
  every file in an array under a literal `files[]` key, which cURL transmits
  verbatim rather than expanding into successive indices as a browser does, so
  the parts were named `files[][0]`, `files[][1]` and a PHP server parsed them
  into a ragged nested array instead of a list. Parts are now named `files[0]`,
  `files[1]`. Repeated calls under the same name append rather than overwrite.

- **A local filesystem path was disclosed as the upload filename.** A `CURLFile`
  built without a posted filename — the form shown first in the README — reports
  an empty one, and cURL then falls back to the full local path, putting it in
  the part header for the receiving server to store and log. The basename is now
  substituted, matching the other attachment types. The caller's object is not
  modified.

- **Nested multipart fields were misnamed and booleans mistyped.** A falsy test
  on the recursion prefix dropped the parent name for key `0`, so the first
  branch of a list collapsed into the root and could overwrite a sibling; array
  merging also renumbered integer-like names. Field names now match
  `http_build_query()` exactly. `false` is sent as `"0"` rather than an empty
  string, and `null` is omitted, so a payload sent as multipart arrives in the
  same shape as one sent form-encoded.

- **A chunked response with trailers lost every header.** cURL appends the
  trailer block to the header buffer, and `Response` kept the last block
  unconditionally, so `Content-Type`, `Set-Cookie` and the rest were replaced by
  the trailers alone — which also broke JSON detection. The last block
  introduced by a status line is now used, so a redirect chain still resolves to
  the final hop.

- **Duplicate headers differing only in case were dropped.** Header names were
  lower-cased after grouping rather than before, giving `Set-Cookie` and
  `set-cookie` separate buckets that were then collapsed to whichever came last.
  A server varying the capitalisation across several `Set-Cookie` lines lost all
  but one. Names are now normalised on insertion.

- **PSR-7 `with*()` methods on `Response` shared the body stream.** Without a
  `__clone()`, a clone copied the stream handle by reference, so reading through
  one response advanced the other and closing one left the other empty. The
  clone now rebuilds its stream on demand.

- **`withBody()` was a no-op on a downloaded response.** The sink path takes
  priority when the body is read back, so a replacement body was visible to
  `getContents()` but ignored by `getRaw()`, `json()` and `data()`, which kept
  returning the file from disk. The sink is now cleared.

- **`withStatus()` accepted any integer.** Codes such as `0`, `-5` and `1000`
  were stored and returned, in violation of PSR-7 and silently corrupting the
  status helpers. Codes outside 100–599 now raise `InvalidArgumentException`.

- **A downloaded body over 5 MB was replaced with a placeholder string.**
  `FileStream::__toString()` returned `[Large Stream: N bytes]` past that size,
  so `getRaw()` on a large download returned the placeholder and `json()` threw
  a syntax error on it. The limit guarded nothing — `getContents()` always read
  the whole file — and has been removed.

- **`FileStream::eof()` reported true before any read**, because the handle is
  opened lazily and the check ran against an unopened one, so a
  `while (!$stream->eof())` loop read nothing at all.

- **`FileStream::read(0)` consumed a byte** instead of returning the empty
  string PSR-7 specifies, because the length was clamped to a minimum of 1.
  A negative length now raises instead of being clamped.

- **`data()` could not read a JSON null, and a trailing wildcard returned
  nothing.** Path resolution used `isset()`, which cannot distinguish a field
  the server sent as `null` from an absent one, so `{"a":null}` yielded the
  default. A path ending in `*` — `items.*` — resolved to a list of nulls rather
  than the items. Wildcard results are also no longer spliced together with
  `array_merge()`: `items.*.tags` returns one entry per item, preserving each
  item's own list instead of flattening them into an unindexable run.

- **`withMultipart()` leaked an owned body stream.** Replacing a body set with
  `withBodyStream()` left the previous stream open and still marked as owned,
  unlike `withBody()` which closes it. It is now closed.

- **`SessionStorage` no longer discards tokens silently.** The constructor
  ignored a failed `session_start()`, so where the session could not be started
  — headers already sent, save handler unavailable — tokens were written into a
  `$_SESSION` that was never persisted, and every request re-fetched a token. It
  now throws instead.

### Added

- `withoutBearerToken()` removes a connection-scoped token from a client.
- `OAuth2::forSubject()` binds a client's cached token to an end user, so a
  shared storage backend does not serve one user's token to another.
- `OAuth2TokenResponse::getError()` returns the provider's `error` — combined
  with `error_description` when present — or `null` when the response carries
  no OAuth2 error.
- CI covering PHP 8.1 through 8.4, a lowest-dependency run, PHPStan level 8,
  PHPMD and PHPCS.
- This changelog and a security policy.

### Changed

- `withHeader()` and `withHeaders()` had inaccurate `@param` annotations
  (`array<string, mixed>` where a list is also accepted); corrected.

- **`withMultipart()` now sets the multipart content type on the first call.**
  A single call previously left the type unset and the request went out
  form-encoded; it now sends `multipart/form-data` as documented. Code that
  relied on the old behaviour to send form-encoded data should call `withForm()`.

- **Multiple files attached under one name are sent as `files[0]`, `files[1]`
  instead of `files[]`.** A server that read the previous ragged nesting rather
  than a list will need updating; a server using a normal multipart parser
  receives the list it always expected.

- **`data()` with a wildcard no longer flattens nested results.**
  `items.*.tags` previously spliced every item's list into one flat run, so the
  result length depended on the data and could not be indexed against the items.
  It now returns one entry per item. A path whose leaf is a scalar is unchanged.

- **`FileStream::__toString()` no longer truncates files over 5 MB** to a
  `[Large Stream: N bytes]` placeholder. Code that tested for that string should
  check `getSize()` instead.

- **OAuth2 storage keys have a new composition.** Tokens, PKCE verifiers and
  CSRF states were stored under `{clientId}`, `{clientId}_pkce_verifier` and
  `{clientId}_oauth_state`; they are now stored under a key derived from the
  client ID, subject, token endpoint and scope. Existing cached entries are not
  found under the new key and are simply re-acquired on the next call. Code that
  reads or writes these entries directly — rather than through the client — must
  be updated; an authorization flow in progress across the upgrade will need to
  be restarted.

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
