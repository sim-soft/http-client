<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use ReflectionMethod;
use RuntimeException;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;

require_once __DIR__ . '/OAuth2PropertyTest.php';
require_once __DIR__ . '/OAuth2AuthCodeTest.php';

/**
 * CustomParamsAuthCodeOAuth2 class.
 *
 * Subclass that overrides buildAuthorizationParams() to add custom parameters.
 * Used for testing Property 11.
 */
class CustomParamsAuthCodeOAuth2 extends AuthCodeTestOAuth2
{
    /** @var string Custom parameter key to add. */
    public string $customKey = '';

    /** @var string Custom parameter value to add. */
    public string $customValue = '';

    /**
     * Override to add custom authorization parameters.
     *
     * @param string $state The CSRF state value.
     * @param string $codeChallenge The PKCE code challenge.
     * @return array<string, string>
     */
    protected function buildAuthorizationParams(string $state, string $codeChallenge): array
    {
        $params = parent::buildAuthorizationParams($state, $codeChallenge);
        $params[$this->customKey] = $this->customValue;

        return $params;
    }
}

/**
 * CustomExchangeParamsOAuth2 class.
 *
 * Subclass that overrides buildCodeExchangeParams() to add custom parameters.
 * Used for testing Property 12.
 */
class CustomExchangeParamsOAuth2 extends AuthCodeTestOAuth2
{
    /** @var string Custom parameter key to add. */
    public string $customKey = '';

    /** @var string Custom parameter value to add. */
    public string $customValue = '';

    /**
     * Override to add custom exchange parameters.
     *
     * @param string $code The authorization code.
     * @param string $verifier The PKCE code verifier.
     * @return array<string, string>
     */
    protected function buildCodeExchangeParams(string $code, string $verifier): array
    {
        $params = parent::buildCodeExchangeParams($code, $verifier);
        $params[$this->customKey] = $this->customValue;

        return $params;
    }
}

/**
 * CustomParseResponseOAuth2 class.
 *
 * Subclass that overrides parseTokenResponse() to modify the TokenData.
 * Used for testing Property 13.
 */
class CustomParseResponseOAuth2 extends AuthCodeTestOAuth2
{
    /** @var string Custom scope to inject into the TokenData. */
    public string $customScope = '';

    /**
     * Override to modify the parsed TokenData.
     *
     * @param OAuth2TokenResponse $response The token endpoint response.
     * @return TokenData
     */
    protected function parseTokenResponse(OAuth2TokenResponse $response): TokenData
    {
        $token = parent::parseTokenResponse($response);

        return new TokenData(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            tokenType: $token->tokenType,
            scope: $this->customScope,
        );
    }
}

/**
 * OAuth2AuthCodePropertyTest class.
 *
 * Property-based tests for the OAuth2 Authorization Code Flow with PKCE.
 * Validates URL generation, PKCE correctness, state management, code exchange,
 * and provider extensibility across many generated inputs.
 *
 * Feature: oauth2-auth-code-flow, Property 1: Authorization URL contains all required parameters
 * Feature: oauth2-auth-code-flow, Property 2: URL parameter encoding round-trip
 * Feature: oauth2-auth-code-flow, Property 3: Code verifier format invariant
 * Feature: oauth2-auth-code-flow, Property 4: PKCE challenge derivation round-trip
 * Feature: oauth2-auth-code-flow, Property 5: URL generation stores verifier and state
 * Feature: oauth2-auth-code-flow, Property 6: State mismatch prevents exchange
 * Feature: oauth2-auth-code-flow, Property 7: Code exchange request contains all required parameters
 * Feature: oauth2-auth-code-flow, Property 8: Successful exchange stores TokenData
 * Feature: oauth2-auth-code-flow, Property 9: Failed exchange throws RuntimeException
 * Feature: oauth2-auth-code-flow, Property 10: State is removed after successful exchange
 * Feature: oauth2-auth-code-flow, Property 11: Subclass authorization params appear in URL
 * Feature: oauth2-auth-code-flow, Property 12: Subclass exchange params appear in request
 * Feature: oauth2-auth-code-flow, Property 13: Subclass parseTokenResponse override is used
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class OAuth2AuthCodePropertyTest extends TestCase
{
    /**
     * Extract query parameters from a URL string.
     *
     * @param string $url The URL to parse.
     * @return array<string, string> The parsed query parameters.
     */
    private function extractQueryParams(string $url): array
    {
        $parts = parse_url($url);
        $params = [];
        parse_str($parts['query'] ?? '', $params);

        /** @var array<string, string> $params */
        return $params;
    }

    /**
     * Property 1: Authorization URL contains all required parameters.
     *
     * For any valid OAuth2 configuration (non-empty clientId, redirectUri,
     * authorizeEndpoint, and optional scope), the generated authorization URL
     * SHALL contain client_id, redirect_uri, response_type=code, state,
     * code_challenge, and code_challenge_method=S256 parameters; and SHALL
     * include scope if and only if scope is configured as non-null.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.3**
     *
     * @return void
     */
    #[Test]
    public function authorizationUrlContainsAllRequiredParameters(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);

                // Must contain all required parameters
                $hasClientId = isset($params['client_id']) && $params['client_id'] === $clientId;
                $hasRedirectUri = isset($params['redirect_uri']) && $params['redirect_uri'] === 'https://myapp.com/callback';
                $hasResponseType = isset($params['response_type']) && $params['response_type'] === 'code';
                $hasState = isset($params['state']) && $params['state'] !== '';
                $hasCodeChallenge = isset($params['code_challenge']) && $params['code_challenge'] !== '';
                $hasChallengeMethod = isset($params['code_challenge_method']) && $params['code_challenge_method'] === 'S256';

                // Scope should NOT be present (AuthCodeTestOAuth2 has no scope)
                $noScope = !isset($params['scope']);

                return $hasClientId && $hasRedirectUri && $hasResponseType
                    && $hasState && $hasCodeChallenge && $hasChallengeMethod && $noScope;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 1b: Authorization URL includes scope when configured.
     *
     * Validates that scope is included if and only if configured.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.3**
     *
     * @return void
     */
    #[Test]
    public function authorizationUrlIncludesScopeWhenConfigured(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2WithScope($clientId, 'secret', $storage);

                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);

                return isset($params['scope']) && $params['scope'] === 'openid profile email';
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 2: URL parameter encoding round-trip.
     *
     * For any string value used as a parameter in the authorization URL,
     * URL-decoding the parameter value from the generated URL SHALL produce
     * the original string value.
     *
     * **Validates: Requirements 1.5**
     *
     * @return void
     */
    #[Test]
    public function urlParameterEncodingRoundTrip(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);

                // URL-decoding the client_id from the URL should produce the original
                return $params['client_id'] === $clientId;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 3: Code verifier format invariant.
     *
     * For any generated code verifier, the verifier SHALL be exactly 128
     * characters long and SHALL contain only characters from the unreserved
     * set (A-Z, a-z, 0-9, -, ., _, ~).
     *
     * **Validates: Requirements 2.1**
     *
     * @return void
     */
    #[Test]
    public function codeVerifierFormatInvariant(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 1000)],
            function (int $iteration): bool {
                $client = new AuthCodeTestOAuth2('client', 'secret', new InMemoryStorage());

                $method = new ReflectionMethod($client, 'generateCodeVerifier');
                /** @var string $verifier */
                $verifier = $method->invoke($client);

                // Must be exactly 128 characters
                $correctLength = strlen($verifier) === 128;

                // Must contain only unreserved characters
                $validChars = preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1;

                return $correctLength && $validChars;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 4: PKCE challenge derivation round-trip.
     *
     * For any valid code verifier string, base64url-decoding the derived code
     * challenge SHALL produce a byte sequence equal to the SHA-256 hash of the
     * original verifier.
     *
     * **Validates: Requirements 2.2, 2.6**
     *
     * @return void
     */
    #[Test]
    public function pkceChallengeDerivationRoundTrip(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 1000)],
            function (int $iteration): bool {
                $client = new AuthCodeTestOAuth2('client', 'secret', new InMemoryStorage());

                $verifierMethod = new ReflectionMethod($client, 'generateCodeVerifier');
                /** @var string $verifier */
                $verifier = $verifierMethod->invoke($client);

                $challengeMethod = new ReflectionMethod($client, 'generateCodeChallenge');
                /** @var string $challenge */
                $challenge = $challengeMethod->invoke($client, $verifier);

                // Base64url-decode the challenge
                $decoded = base64_decode(strtr($challenge, '-_', '+/'), true);

                // Compare with SHA-256 of the verifier
                $expectedHash = hash('sha256', $verifier, true);

                return $decoded === $expectedHash;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 5: URL generation stores verifier and state.
     *
     * After getAuthorizationUrl(), storage has both {clientId}_pkce_verifier
     * and {clientId}_oauth_state.
     *
     * **Validates: Requirements 2.5, 3.1, 3.2**
     *
     * @return void
     */
    #[Test]
    public function urlGenerationStoresVerifierAndState(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                $client->getAuthorizationUrl();

                $hasVerifier = $storage->has("{$clientId}_pkce_verifier");
                $hasState = $storage->has("{$clientId}_oauth_state");

                // Verifier should be a non-empty string
                $verifier = $storage->get("{$clientId}_pkce_verifier");
                $validVerifier = is_string($verifier) && $verifier !== '';

                // State should be a non-empty string
                $state = $storage->get("{$clientId}_oauth_state");
                $validState = is_string($state) && $state !== '';

                return $hasVerifier && $hasState && $validVerifier && $validState;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 6: State mismatch prevents exchange.
     *
     * For any two distinct strings used as stored/provided state,
     * exchangeCode() throws RuntimeException without HTTP call.
     *
     * **Validates: Requirements 3.3, 3.4, 4.1**
     *
     * @return void
     */
    #[Test]
    public function stateMismatchPreventsExchange(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::asciiStrings()->notEmpty(),
            ],
            function (string $storedState, string $providedState): bool {
                // Ensure they are distinct
                if ($storedState === $providedState) {
                    return true; // Skip equal pairs
                }

                $storage = new InMemoryStorage();
                $clientId = 'test-client';
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                // Manually store state and verifier
                $storage->set("{$clientId}_oauth_state", $storedState);
                $storage->set("{$clientId}_pkce_verifier", 'some-verifier');

                $threw = false;
                try {
                    $client->exchangeCode('some-code', $providedState);
                } catch (RuntimeException) {
                    $threw = true;
                }

                // Must throw and must NOT have made any HTTP call
                return $threw && $client->requestCount === 0;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 7: Code exchange request contains all required parameters.
     *
     * For any valid code and verifier, the POST contains grant_type=authorization_code,
     * code, redirect_uri, client_id, client_secret, code_verifier.
     *
     * **Validates: Requirements 2.4, 4.2**
     *
     * @return void
     */
    #[Test]
    public function codeExchangeRequestContainsAllRequiredParameters(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::asciiStrings()->notEmpty(),
            ],
            function (string $code, string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                // Get the stored verifier before exchange removes it
                $verifier = $storage->get("{$clientId}_pkce_verifier");

                $client->exchangeCode($code, $state);

                $captured = $client->capturedParams[0] ?? [];

                $hasGrantType = ($captured['grant_type'] ?? '') === 'authorization_code';
                $hasCode = ($captured['code'] ?? '') === $code;
                $hasRedirectUri = ($captured['redirect_uri'] ?? '') === 'https://myapp.com/callback';
                $hasClientId = ($captured['client_id'] ?? '') === $clientId;
                $hasClientSecret = ($captured['client_secret'] ?? '') === 'secret';
                $hasCodeVerifier = ($captured['code_verifier'] ?? '') === $verifier;

                return $hasGrantType && $hasCode && $hasRedirectUri
                    && $hasClientId && $hasClientSecret && $hasCodeVerifier;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 8: Successful exchange stores TokenData.
     *
     * For any successful response, storage contains TokenData under clientId key.
     *
     * **Validates: Requirements 4.3, 4.6**
     *
     * @return void
     */
    #[Test]
    public function successfulExchangeStoresTokenData(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::asciiStrings()->notEmpty(),
                Gen::choose(60, 7200),
            ],
            function (string $clientId, string $accessToken, int $expiresIn): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                $client->nextResponse = new OAuth2TokenResponse(
                    curlInfo: ['http_code' => 200],
                    body: json_encode([
                        'access_token' => $accessToken,
                        'token_type' => 'Bearer',
                        'expires_in' => $expiresIn,
                        'refresh_token' => 'refresh-123',
                    ], JSON_THROW_ON_ERROR),
                );

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                $client->exchangeCode('auth-code', $state);

                $stored = $storage->get($clientId);

                return $stored instanceof TokenData
                    && $stored->accessToken === $accessToken
                    && $stored->refreshToken === 'refresh-123';
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 9: Failed exchange throws RuntimeException.
     *
     * For any non-successful HTTP response, exchangeCode() throws
     * RuntimeException with status code in message.
     *
     * **Validates: Requirements 4.4**
     *
     * @return void
     */
    #[Test]
    public function failedExchangeThrowsRuntimeException(): void
    {
        $property = Property::forAll(
            [Gen::choose(400, 599)],
            function (int $statusCode): bool {
                $storage = new InMemoryStorage();
                $clientId = 'test-client';
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                $client->nextResponse = new OAuth2TokenResponse(
                    curlInfo: ['http_code' => $statusCode],
                    body: json_encode([
                        'error' => 'invalid_grant',
                        'error_description' => 'Code expired',
                    ], JSON_THROW_ON_ERROR),
                );

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                try {
                    $client->exchangeCode('auth-code', $state);
                    return false; // Should have thrown
                } catch (RuntimeException $exception) {
                    // Message must contain the status code
                    return str_contains($exception->getMessage(), (string)$statusCode);
                }
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 10: State is removed after successful exchange.
     *
     * After successful exchangeCode(), state key no longer exists in storage.
     *
     * **Validates: Requirements 3.5**
     *
     * @return void
     */
    #[Test]
    public function stateIsRemovedAfterSuccessfulExchange(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $clientId): bool {
                $storage = new InMemoryStorage();
                $client = new AuthCodeTestOAuth2($clientId, 'secret', $storage);

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                // Verify state exists before exchange
                $stateExistsBefore = $storage->has("{$clientId}_oauth_state");

                $client->exchangeCode('auth-code', $state);

                // Verify state is removed after exchange
                $stateExistsAfter = $storage->has("{$clientId}_oauth_state");

                return $stateExistsBefore && !$stateExistsAfter;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 11: Subclass authorization params appear in URL.
     *
     * For any custom key-value pair added by override, the URL contains
     * that parameter.
     *
     * **Validates: Requirements 6.1, 6.4**
     *
     * @return void
     */
    #[Test]
    public function subclassAuthorizationParamsAppearInUrl(): void
    {
        $property = Property::forAll(
            [
                Gen::choose(1, 10000),
                Gen::asciiStrings()->notEmpty(),
            ],
            function (int $keySuffix, string $customValue): bool {
                // Use a safe alphanumeric key to avoid URL encoding issues with parse_str
                $customKey = "custom_param_{$keySuffix}";

                $storage = new InMemoryStorage();
                $client = new CustomParamsAuthCodeOAuth2('client-id', 'secret', $storage);
                $client->customKey = $customKey;
                $client->customValue = $customValue;

                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);

                return isset($params[$customKey]) && $params[$customKey] === $customValue;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 12: Subclass exchange params appear in request.
     *
     * For any custom key-value pair added by override, the POST body
     * contains that parameter.
     *
     * **Validates: Requirements 6.2**
     *
     * @return void
     */
    #[Test]
    public function subclassExchangeParamsAppearInRequest(): void
    {
        $property = Property::forAll(
            [
                Gen::choose(1, 10000),
                Gen::asciiStrings()->notEmpty(),
            ],
            function (int $keySuffix, string $customValue): bool {
                // Use a safe alphanumeric key to avoid conflicts
                $customKey = "custom_param_{$keySuffix}";

                $storage = new InMemoryStorage();
                $client = new CustomExchangeParamsOAuth2('client-id', 'secret', $storage);
                $client->customKey = $customKey;
                $client->customValue = $customValue;

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                $client->exchangeCode('auth-code', $state);

                $captured = $client->capturedParams[0] ?? [];

                return isset($captured[$customKey]) && $captured[$customKey] === $customValue;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property 13: Subclass parseTokenResponse override is used.
     *
     * For any override that modifies the TokenData, the stored token
     * reflects the modification.
     *
     * **Validates: Requirements 6.3, 6.5**
     *
     * @return void
     */
    #[Test]
    public function subclassParseTokenResponseOverrideIsUsed(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $customScope): bool {
                $storage = new InMemoryStorage();
                $clientId = 'test-client';
                $client = new CustomParseResponseOAuth2($clientId, 'secret', $storage);
                $client->customScope = $customScope;

                // Generate URL to store state and verifier
                $url = $client->getAuthorizationUrl();
                $params = $this->extractQueryParams($url);
                $state = $params['state'];

                $client->exchangeCode('auth-code', $state);

                $stored = $storage->get($clientId);

                return $stored instanceof TokenData
                    && $stored->scope === $customScope;
            }
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }
}
