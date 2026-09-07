# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 2.2.4 and earlier were reconstructed from commit history after the
fact, so they summarise each release rather than list every change.

## [Unreleased]

## [2.4.1] - 2026-09-07

### Fixed

- **`revokeToken()` evicted the cache entry for whichever subject the client
  was currently bound to, not the one holding the revoked token.** The two are
  not always the same entry: the cache is keyed by client, subject, endpoint
  and scope. Revoking one user's token through a client bound to another
  dropped the second user's still-valid token, while the revoked one stayed
  cached and was served until it expired — every request with it returning 401.
  The entry is now removed only when it holds the revoked token, compared
  against both the access and the refresh token, since revoking a refresh token
  invalidates the pair at most providers. `StorageInterface` has no
  enumeration, so an entry belonging to a different subject still cannot be
  located; `docs/OAUTH2.md` now says so and points at `invalidate()`.

- **Tokens shorter-lived than the expiry buffer were cached already expired.**
  `toTokenData()` subtracted the buffer from the provider's expiry with no
  floor, so a token issued with `expires_in` below the buffer — the default is
  30 seconds — produced a timestamp in the past. The token worked, but
  `hasExpired()` was true from the first read, so every call re-requested one
  and caching was silently off for exactly the tokens that most need it. The
  buffer is now capped at the token's own lifetime, leaving at least one second
  of usable window, and never pushes expiry past what the provider reported.

- **`expiryBuffer()` accepted negative values, inverting the buffer into an
  extension.** With `expiryBuffer(-600)` and a token issued for 60 seconds, the
  client treated it as valid for 600 seconds after the provider had expired it.
  A negative buffer is a configuration error rather than a mode of operation,
  so it now throws `InvalidArgumentException` and leaves the buffer unchanged.
  Zero remains valid and still means no buffer.

### Security

- **The six vulnerabilities fixed in 2.3.0 now have published advisories.** The
  fixes shipped on 2026-09-06 but were only described here, so `composer audit`
  reported nothing and an installation pinned below 2.3.0 gave its operator no
  signal. GHSA-8h2v-4qj5-vgqc (High, CVSS 8.1) covers OAuth2 tokens being shared
  between users of the same application; GHSA-757q-r7r7-xcq5, privilege
  substitution after a user's token expires; GHSA-7227-w5mp-vq94, bearer tokens
  disclosed to third-party hosts through PSR-18; GHSA-xcwj-p779-5r4c, header
  injection; GHSA-x6m6-wjx4-v49v, OAuth2 token files readable by other local
  users; and GHSA-wjpm-p27f-99wc, OAuth2 CSRF state replay. All are fixed in
  2.3.0 and none is a new issue in this release. Nothing in the library changed;
  what changed is that a tool can now tell you when you are running an affected
  version.

### Documentation

- **The documentation site shows the version it describes.** The site named no
  release anywhere, so a reader had no way to tell whether what they were
  reading matched the version they had installed. The badge sits under the
  sidebar title and links to the release it names. It reads the number at
  runtime from the GitHub releases API rather than hard-coding it, so the site
  cannot drift out of date on the next tag, and stays hidden when that call
  does not return — offline, rate-limited or blocked leaves the sidebar exactly
  as it was. The same commit removed "zero-dependency" from four metadata
  strings in `docs/index.html`, which the 2.4.0 passes over that claim had
  missed by grepping only `*.md` and `*.json`.

## [2.4.0] - 2026-09-07

### Changed

- **The distributed package no longer carries development material.**
  `.gitattributes` now marks `tests/`, `.kiro/`, `.github/`, the PHPUnit,
  PHPStan, PHPMD and PHPCS configs, the PHPCS baseline, the IDE workspace file
  and `.editorconfig` as `export-ignore`. The dist archive drops from 130 files
  to 54, and from 1.2 MB to 460 KB — what remains is `src/` (35 files), `docs/`
  (14), `composer.json`, the README, this changelog, the licence and the
  security policy. Well over half of every `composer require` download was
  previously material a consumer cannot use: none of it is autoloaded, since
  `autoload` maps `src/` only and `tests/` sits under `autoload-dev`, which
  Composer ignores for dependencies.

  Nothing a consumer can reach was removed. `FakeHttpClient` and the rest of
  the testing helpers live in `src/Testing/`, not `tests/`, so test suites
  built on them are unaffected. `docs/` is kept as the offline copy of the
  tutorials the README links to. Verified by installing the trimmed archive
  into an empty project: it resolves to four packages, autoloads every
  consumer-facing class, and completes a live HTTPS request.

### Added

- **Integration tests that perform real HTTP transfers.** The existing suite
  asserts on cURL options before execution and never opens a socket, so
  response parsing, redirect following, sink streaming and concurrent
  transfers had no coverage against a real server. These run against a PHP
  built-in server on the loopback interface and need no network access.

### Fixed

- **A broken anchor in the online documentation.** The Timeouts entry in the
  `docs/README.md` table of contents used a GitHub-style slug, which docsify
  does not generate for a heading containing an ampersand, so the link
  resolved to nothing and the click scrolled nowhere.

### Documentation

- **The response status predicates are documented in full.** The README listed
  8 of the 19 available checks. All 19 are now covered, split into broad
  checks and exact status codes, along with the request-body helpers
  `asMultipart()` and `asRaw()`, the connection-tuning options
  `withBufferSize()`, `withDNSTimeout()` and `withoutReturnTransfer()`, and
  the rules governing which requests a retry actually repeats — a 5xx is
  retried only for `GET`, `HEAD` and `OPTIONS`, so a failed `POST` cannot be
  replayed into a duplicate order or charge.

- **The OAuth2 token response is documented.** `OAUTH2.md` covered the client
  but stopped at the response it parses, leaving `getTokenType()`,
  `getExpiresIn()`, `getExpiresAt()`, `getRefreshToken()`, `getScope()` and
  `getError()` undiscoverable, along with `withScope()` on the client. Three
  behaviours that are not guessable from the signatures are now called out:
  `getExpiresIn()` is a duration and `getExpiresAt()` an instant; `getError()`
  must be consulted even on a 2xx response, because RFC 6749 §5.2 lets a
  provider report failure in the body; and the scope forms part of the token
  cache key, so each scope caches its own token.

- **The library comparison is now measured rather than asserted.**
  `COMPARISON.md` claimed "zero dependencies / only requires ext-curl", which
  is not what `composer require` does — it installs four packages. The claim
  was true in spirit and wrong in fact, and a reader who checked would have
  found it wrong. The table now reports package counts and installed sizes
  taken from a real `composer install --no-dev` for each library, and explains
  what the three PSR packages actually are: interface-only, no implementation
  code, no transitive dependencies. Comparison figures are dated and version-
  pinned so they can be re-checked. Two trade-offs that were missing are now
  stated — PSR-7 is implemented on the response side only, and there is no
  third-party provider ecosystem — alongside the built-in OAuth2 row, which is
  the clearest advantage over the alternatives and was absent from the table.

- **The same claim is corrected everywhere else it appeared.** The package
  description on Packagist and the opening line of both READMEs described the
  library as having "zero runtime dependencies". The READMEs now say what is
  verifiable: the only dependencies are the PSR interface packages, which ship
  no implementation code. The Packagist description drops the claim rather than
  qualifying it, since a one-line blurb is the wrong place to explain a
  dependency tree — `COMPARISON.md` carries the detail. Nothing about the
  dependencies changed, only the description of them.

## [2.3.0] - 2026-09-06

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

- **OAuth2 token permissions could be skipped on PHP 8.2.** `FileStorage`
  returns early when a path already carries the intended mode, but read that
  mode through `fileperms()` without clearing the stat cache. `chmod()` only
  began invalidating that cache in PHP 8.3, so on 8.2 a path whose mode changed
  earlier in the same process reported its old mode and the re-tightening
  `chmod` was skipped. The cache is now cleared before the check. Found by
  running the suite on Linux, where the POSIX permission tests are not skipped.

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

- **A pooled request no longer stalls the batch it shares a client with.**
  `buildHandle()` handed out the instance's shared cURL handle, and a cURL
  handle may be attached to a `curl_multi` only once. Submitting the same client
  twice in one `send()` — or letting the pool retry one — had the second
  `curl_multi_add_handle()` rejected with `CURLM_ADDED_ALREADY`; the pool then
  waited on a transfer that was never running and hung until the process was
  killed. `buildHandle()` now returns a handle of its own each call. Sequential
  `request()` calls still reuse one handle, so connection reuse is unchanged.

- **`HttpPool` returned responses in key order, not input order.**
  `getResponses()` and `foreach` sorted the keys, so `['zebra' => …, 'alpha' =>
  …]` came back alpha-first and ids listed `5, 1, 3` came back `1, 3, 5` —
  against the order documented in `docs/POOL.md`. Access by key was correct
  throughout; only iteration and `array_keys()` were affected. Responses now
  follow the input array.

- **A pooled download was truncated to the last full buffer.** cURL writes the
  sink through PHP's buffered stream, and a `curl_multi` transfer does not flush
  it at the end the way `curl_exec()` does, so a 200 KB download read back as
  196 608 bytes until the client happened to be destroyed. The pool now flushes
  each sink as its transfer completes.

- **A pooled download that was retried kept the tail of the failed attempt.**
  The retry truncated the file while the failed response was still buffered, so
  the buffer drained afterwards and left the old bytes behind the new ones — a
  300-byte success after a 500-byte error produced a 500-byte file. The stream
  is now settled before it is truncated.

- **`HttpPool` left the clients it executed holding their request state.** The
  pool drives the handle itself, so the reset that ends `request()` never ran:
  after a pool the client still carried the URL, method, query, body and
  per-request headers of the pooled request, and the next fluent call inherited
  them. State is now released per request as it completes.

- **`PoolBuilder::put()`, `patch()` and `delete()` sent POST.** Applying a body
  went through `withMultipart()`, which forces POST, so every builder request
  with a body reached the server as a POST regardless of the verb asked for.
  Requests without a body were unaffected. The requested verb is now
  authoritative.

- **`PoolBuilder` raised a `TypeError` for a string or stream body.** The verb
  methods accept `mixed`, but the body was passed straight to `withMultipart()`
  or `withJson()`, both of which require an array. A string body is now sent
  raw, a `StreamInterface` is used as the body, and anything else raises
  `InvalidArgumentException` naming the type.

- **Timeouts set through `withOptions()` were ignored.** `applyTransferOptions()`
  writes the `timeout()` and `connectionTimeout()` properties into the option
  array on every request, overwriting whatever the caller had stored for
  `CURLOPT_TIMEOUT` or `CURLOPT_CONNECTTIMEOUT`; a client asking for a 1-second
  timeout waited the 30-second default. Both options are now routed to their
  setters, so the last call wins whichever API is used. A non-integer value for
  either raises `InvalidArgumentException` instead of being ignored.

- **`getEndpoint()` joined the base URL and resource by concatenation.** A base
  with a trailing slash produced `https://api.test//users`, a different path to
  most routers; a resource without a leading slash produced
  `https://api.test users` run together as `https://api.testusers`, a different
  host. The two are now joined by exactly one slash, and a resource that is
  itself an absolute URL is used as given.

- **A second `sink()` call closed a caller's stream and leaked the client's
  own.** The ownership flag was set when a path was opened and never cleared,
  so a later `sink($resource)` inherited it and the client closed a handle it
  never opened — while the file it had opened was left dangling. Ownership is
  now released with the sink it describes.

- **Mixin methods that do not return a `Closure` were unusable.** They were
  registered as an `[$object, 'method']` pair, which `__call()` then tried to
  rebind to the host instance; PHP refuses to rebind a method closure across an
  unrelated class, so the call died with a `TypeError` — and a protected method
  never got that far, being uncallable outside the mixin's scope. Such methods
  are now wrapped in a forwarding closure that keeps the mixin as the receiver.

- **`formData()` sent url-encoded fields instead of multipart.** The deprecated
  method forwarded to `withForm()`, contradicting both its name and the
  `withMultipart()` named in its own deprecation notice, and silently changing
  the request for callers who had not migrated. It now forwards to
  `withMultipart()`, as it always did before.

- **`HttpClient::make()` ignored subclasses.** `new self()` returned an
  `HttpClient` even when called as `MyClient::make()`, dropping whatever the
  subclass added. The factory now instantiates the called class.

- **An empty response sequence failed inside the library.** `sequence($pattern, [])`
  built a route that matched and then died in `nextResponse()` with an
  undefined-index warning and a `TypeError`, pointing at the library rather than
  at the empty call. `FakeRoute` now rejects an empty sequence at construction.

- **`FakeHttpClient` ignored `sink()`.** The real client hands the body to cURL,
  which writes it to the sink; the fake never reaches cURL, so a test asserting
  on a downloaded file found it empty. The fake now writes the final response
  body to a configured sink.

### Added

- `withoutBearerToken()` removes a connection-scoped token from a client.
- `HttpClient::releaseRequest()` settles and discards per-request state for a
  client whose handle was executed by an external driver. `HttpPool` calls it;
  code driving `buildHandle()` directly should too.
- `OAuth2::forSubject()` binds a client's cached token to an end user, so a
  shared storage backend does not serve one user's token to another.
- `OAuth2TokenResponse::getError()` returns the provider's `error` — combined
  with `error_description` when present — or `null` when the response carries
  no OAuth2 error.
- CI covering PHP 8.2 through 8.4, a lowest-dependency run, PHPStan level 8,
  PHPMD and PHPCS.
- This changelog and a security policy.

### Changed

- **Minimum PHP raised from 8.1 to 8.2.** PHPUnit 11 requires PHP >= 8.2, so
  the declared `^8.1` support could not be exercised by the test suite at all —
  8.1 was claimed but never verified. The CI matrix, badges and documentation
  now cover 8.2 through 8.4.

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

- **`HttpClient::buildHandle()` returns a new handle per call** rather than the
  instance's shared one, so that a handle can be attached to a `curl_multi`.
  Code calling it directly is now responsible for closing each handle it
  receives — the destructor no longer covers them — and should call
  `releaseRequest()` when the transfer is done.

- **`HttpClient` declares an explicit no-argument constructor.** A subclass that
  needs constructor parameters must give them defaults, so that `make()` can
  instantiate the called class.

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

[Unreleased]: https://github.com/sim-soft/http-client/compare/2.4.1...HEAD
[2.4.1]: https://github.com/sim-soft/http-client/compare/2.4.0...2.4.1
[2.4.0]: https://github.com/sim-soft/http-client/compare/2.3.0...2.4.0
[2.3.0]: https://github.com/sim-soft/http-client/compare/2.2.4...2.3.0
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
