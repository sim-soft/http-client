<?php

namespace Simsoft\HttpClient\Exceptions;

use RuntimeException;

/**
 * ScopeEscalationException class.
 *
 * Raised when a token refresh returns a scope wider than the one originally
 * granted, which RFC 6749 §6 does not permit.
 *
 * This is deliberately not a subclass of the exceptions raised by an ordinary
 * refresh failure. A refresh that fails is recoverable — the client falls back
 * to acquiring a fresh token — but a provider returning authority it never
 * granted is not a transport problem to retry around. Retrying would obtain a
 * token by a different grant and carry on as though nothing had happened,
 * leaving the discrepancy unreported. It propagates to the caller instead.
 */
class ScopeEscalationException extends RuntimeException
{
}
