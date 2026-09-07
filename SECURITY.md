# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| 2.3.x   | Yes       |
| 2.0–2.2 | No        |
| 1.x     | No        |

Security fixes are released for the latest 2.3.x patch line only. Upgrade to
the current release before reporting an issue against an older version.

2.3.0 fixes several security issues present in every earlier release, including
header injection and OAuth2 token disclosure between users on a shared storage
backend. See the [changelog](CHANGELOG.md) for details. Earlier lines will not
receive backports, so upgrading is the only remedy.

## Reporting a Vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through
[GitHub Security Advisories](https://github.com/sim-soft/http-client/security/advisories/new),
or by email to vzangloo@7mayday.com.

Please include:

- The affected version.
- A description of the issue and its impact.
- Steps to reproduce, ideally a short PHP snippet.
- Any suggested fix, if you have one.

This is a small, volunteer-maintained project, so response times are best
effort rather than guaranteed. You can expect an acknowledgement within a week.
If a report is accepted, the fix and an advisory will be published together,
and you will be credited unless you ask otherwise.

## Scope

This library makes outbound HTTP requests on behalf of an application. Issues
in scope include anything that causes it to send or accept something the
calling application did not intend:

- Request smuggling or header injection.
- Leaking credentials — into logs, debug output, error messages, files, or
  across a redirect to another host.
- Weakened TLS verification, or a URL or redirect escaping its intended scheme.
- Unsafe deserialization of stored or received data.

Out of scope:

- Behaviour of a remote server the client is pointed at.
- Anything that requires the calling application to pass attacker-controlled
  values to configuration methods such as `withOptions()` or
  `withoutVerifying()`, which exist precisely to override defaults.
- Vulnerabilities in dependencies; report those to their maintainers.

## Security-Relevant Defaults

Worth knowing when integrating:

- **TLS verification is on by default** (`CURLOPT_SSL_VERIFYPEER` and
  `CURLOPT_SSL_VERIFYHOST`). `withoutVerifying()` disables it and should never
  be used against production endpoints.
- **Protocols are restricted to HTTP and HTTPS**, for the initial request and
  for redirects, so a redirect cannot downgrade to `file://` or similar.
- **Redirects are followed** by default, up to 5. cURL strips the
  `Authorization` header when a redirect crosses to a different host, so a
  token set with `withBearerToken()` is not forwarded. **Custom headers are
  not protected this way** — a credential passed through `withHeader()`, such
  as `X-Api-Key`, *is* sent to the redirect target. When sending a custom
  credential header to an endpoint you do not control, disable redirects with
  `withOptions([CURLOPT_FOLLOWLOCATION => false])`.
- **Bearer tokens are connection-scoped.** A token set with
  `withBearerToken()` persists on the client and is sent with every subsequent
  request, so treat a configured client as a credential-bearing object. Use
  `withoutBearerToken()` before handing it to unrelated code.
- **PSR-18 sends do not carry the token off-origin.** `sendRequest()` withholds
  a connection-scoped token when the PSR-7 request targets a scheme/authority
  other than the client's base URL, so a client handed to a third-party SDK
  does not disclose its credential to that SDK's host. An `Authorization`
  header set on the PSR-7 request itself is the caller's explicit intent and is
  always sent. A client with no base URL has no origin to compare against, so
  its token applies to every PSR-18 send.
- **Header names and values are validated.** Names must be RFC 7230 tokens;
  values may not contain CR, LF or NUL. Invalid input throws
  `InvalidArgumentException` rather than reaching the wire.
- **Request logging omits headers and bodies**, recording only method, URL,
  status, duration and error code, so credentials do not reach logs. Note that
  `dump()` and `dd()` do print headers, including any bearer token — they are
  debugging tools and their output should not be exposed.
- **OAuth2 tokens stored by `FileStorage`** are written `0600` inside a `0700`
  directory, and deserialization is restricted to `TokenData`. The default
  location is the system temp directory; on a shared host, pass an explicit
  path owned by the application user.
