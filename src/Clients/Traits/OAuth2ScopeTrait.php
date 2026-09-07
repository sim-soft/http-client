<?php

namespace Simsoft\HttpClient\Clients\Traits;

use Closure;
use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Exceptions\ScopeEscalationException;

/**
 * OAuth2ScopeTrait.
 *
 * Reconciles the scope on a refreshed token against the one it replaces, for
 * the OAuth2 class.
 *
 * A refresh is not a fresh grant, so the scope it returns is only meaningful
 * relative to what was already held — which is why this cannot be decided when
 * the response is parsed, and lives here instead.
 */
trait OAuth2ScopeTrait
{
    /** @var Closure|null Callback invoked when a refresh narrows the scope. Receives (?string $was, ?string $now). */
    protected ?Closure $onScopeChanged = null;

    /**
     * Register a callback invoked when a refresh returns a narrower scope.
     *
     * A provider may grant less on refresh than it granted originally, and RFC
     * 6749 permits it. The refreshed token is still valid and is still returned;
     * what changes is that calls needing the dropped permission start coming
     * back 403, with nothing linking them to the refresh that caused it. This is
     * the signal. Use it to log, alert, or send the user back through
     * authorization.
     *
     * Fires only on a genuine reduction. An unchanged scope, a reordering of the
     * same values, and a scope the provider omitted — which RFC 6749 §5.1 defines
     * as identical to the original — are all silent.
     *
     * @param Closure $callback Receives (?string $previousScope, ?string $newScope).
     * @return $this
     */
    public function onScopeChanged(Closure $callback): self
    {
        $this->onScopeChanged = $callback;
        return $this;
    }

    /**
     * Reconcile the scope on a refreshed token against the one it replaces.
     *
     * Three cases, each defined by RFC 6749:
     *
     * - **Omitted** (§5.1): the scope is identical to the original. The value is
     *   carried across rather than recorded as null, so a client that knew what
     *   it held does not forget it on an ordinary refresh.
     * - **Wider** (§6): forbidden — a refresh may not exceed the original grant.
     *   Raised rather than stored, since there is no legitimate reading of a
     *   provider returning authority it did not grant, and recording it would
     *   have the client assert a permission it does not have.
     * - **Narrower** (§6): permitted. The token is kept and returned, and
     *   `onScopeChanged()` fires so the reduction is observable rather than
     *   surfacing later as unexplained 403s.
     *
     * @param TokenData $previous The token being replaced.
     * @param TokenData $refreshed The token returned by the refresh.
     * @return TokenData The refreshed token, with scope reconciled.
     * @throws ScopeEscalationException When the refresh returns a wider scope.
     */
    protected function reconcileRefreshedScope(TokenData $previous, TokenData $refreshed): TokenData
    {
        // No prior scope to compare against; nothing can be said about a change.
        if ($previous->scope === null) {
            return $refreshed;
        }

        // Omitted on refresh means unchanged (RFC 6749 §5.1).
        if ($refreshed->scope === null) {
            return $this->withScopeValue($refreshed, $previous->scope);
        }

        $was = $this->scopeSet($previous->scope);
        $now = $this->scopeSet($refreshed->scope);

        $gained = array_diff($now, $was);

        if ($gained !== []) {
            throw new ScopeEscalationException(sprintf(
                'Token refresh for client "%s" returned the scope "%s", which exceeds the "%s" '
                . 'originally granted by adding "%s". RFC 6749 §6 does not permit a refresh to '
                . 'widen a grant.',
                $this->clientId,
                $refreshed->scope,
                $previous->scope,
                implode(' ', $gained)
            ));
        }

        $lost = array_diff($was, $now);

        if ($lost !== [] && $this->onScopeChanged !== null) {
            ($this->onScopeChanged)($previous->scope, $refreshed->scope);
        }

        return $refreshed;
    }

    /**
     * Split a scope string into its set of values.
     *
     * RFC 6749 §3.3 defines scope as a space-delimited list whose order carries
     * no meaning, so it is compared as a set. Repeated whitespace is tolerated
     * because providers do emit it.
     *
     * @param string $scope The scope string.
     * @return array<int, string> The distinct scope values.
     */
    private function scopeSet(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($parts === false ? [] : $parts));
    }

    /**
     * Copy a token, replacing only its scope.
     *
     * @param TokenData $token The token to copy.
     * @param string|null $scope The scope to record.
     * @return TokenData The copy.
     */
    private function withScopeValue(TokenData $token, ?string $scope): TokenData
    {
        return new TokenData(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            tokenType: $token->tokenType,
            scope: $scope,
            metadata: $token->metadata,
        );
    }
}
