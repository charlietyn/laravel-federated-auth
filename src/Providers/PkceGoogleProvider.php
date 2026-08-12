<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Exception;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderTokenExpiredException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderTokenNotYetValidException;

/**
 * Google Socialite provider adapted for package-managed, server-side OAuth
 * state. The code verifier is restored from the one-time state instead of a
 * Laravel session, and every accepted ID token is cryptographically verified.
 */
class PkceGoogleProvider extends GoogleProvider
{
    private ?string $externalCodeVerifier = null;

    private array $verifiedIdTokenClaims = [];

    public function withCodeVerifier(?string $codeVerifier): self
    {
        $this->externalCodeVerifier = $codeVerifier;

        return $this;
    }

    /**
     * Complete the browser authorization-code exchange and require a signed
     * Google ID token whose audience is this channel's configured client ID.
     */
    public function userWithVerifiedIdToken(?string $expectedNonce = null): User
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $idToken = Arr::get($response, 'id_token');
        $accessToken = Arr::get($response, 'access_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new InvalidProviderTokenException('Google did not return an ID token.');
        }

        if (! is_string($accessToken) || $accessToken === '') {
            throw new InvalidProviderTokenException('Google did not return an access token.');
        }

        $claims = $this->verifiedClaims($idToken, $expectedNonce);
        $profile = $this->getUserByToken($accessToken);
        $claimSubject = (string) ($claims['sub'] ?? '');
        $profileSubject = (string) ($profile['sub'] ?? '');

        if ($claimSubject === '' || ($profileSubject !== '' && ! hash_equals($claimSubject, $profileSubject))) {
            throw new InvalidProviderTokenException('Google identity subject mismatch.');
        }

        $user = array_replace($profile, $claims);

        return $this->userInstance($response, $user);
    }

    /**
     * Native/mobile flow. The supplied value is an ID token, not an access
     * token, so it is verified locally against Google's JWKS and mapped directly
     * from claims. No userinfo request is attempted with the wrong token type.
     */
    public function userFromVerifiedIdToken(
        string $idToken,
        ?string $expectedNonce = null,
    ): User {
        $claims = $this->verifiedClaims($idToken, $expectedNonce);
        $subject = (string) ($claims['sub'] ?? '');

        if ($subject === '') {
            throw new InvalidProviderTokenException('Google ID token is missing its subject.');
        }

        return $this->mapUserToObject($claims);
    }

    public function verifiedIdTokenClaims(): array
    {
        return $this->verifiedIdTokenClaims;
    }

    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);

        if ($this->externalCodeVerifier !== null && $this->externalCodeVerifier !== '') {
            $fields['code_verifier'] = $this->externalCodeVerifier;
        }

        return $fields;
    }

    /**
     * Verify Google's JWT without Socialite's lossy exception wrapper.
     *
     * Socialite rethrows JWT failures using only their message, which discards
     * the original BeforeValidException / ExpiredException type. Keeping the
     * original exception lets hosts distinguish clock skew from an invalid
     * signature.
     *
     * Signature, JWKS and audience verification are performed exactly as
     * upstream. The issuer check is deliberately WIDER: Socialite accepts only
     * `https://accounts.google.com`, while Google documents `iss` as either
     * that or the bare `accounts.google.com`, and issues both in practice. Both
     * values are Google-controlled and are only reached after the signature has
     * been verified against Google's JWKS, so accepting the documented pair
     * fixes false rejections without widening trust.
     */
    protected function getUserFromJwtToken($idToken)
    {
        $claims = (array) JWT::decode(
            $idToken,
            JWK::parseKeySet($this->getGoogleJwks()),
        );

        if (! isset($claims['iss']) || ! in_array($claims['iss'], [
            'accounts.google.com',
            'https://accounts.google.com',
        ], true)) {
            throw new Exception('Invalid Google ID token issuer.');
        }

        if (! isset($claims['aud']) || $claims['aud'] !== $this->clientId) {
            throw new Exception('Invalid Google ID token audience.');
        }

        return $claims;
    }

    private function verifiedClaims(
        string $idToken,
        ?string $expectedNonce,
    ): array {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('federated-auth.security.oidc.clock_skew_seconds', 60));

        try {
            // GoogleProvider verifies the signature, issuer, configured client
            // audience and JWT temporal claims through firebase/php-jwt/JWKS.
            $claims = $this->getUserFromJwtToken($idToken);
        } catch (\Throwable $exception) {
            $temporalException = $this->findException($exception, BeforeValidException::class);

            if ($temporalException) {
                throw new ProviderTokenNotYetValidException(
                    'Google ID token is not valid yet. Check the application server clock.',
                    previous: $exception,
                );
            }

            $expiredException = $this->findException($exception, ExpiredException::class);

            if ($expiredException) {
                throw new ProviderTokenExpiredException(
                    'Google ID token has expired.',
                    previous: $exception,
                );
            }

            throw new InvalidProviderTokenException(
                'Google ID token verification failed.',
                previous: $exception,
            );
        } finally {
            // firebase/php-jwt exposes leeway as mutable process-wide state.
            // Restore it so this verification cannot affect unrelated JWTs in
            // long-running workers or later requests handled by the same PHP VM.
            JWT::$leeway = $previousLeeway;
        }

        if ($expectedNonce !== null && $expectedNonce !== '') {
            $actualNonce = (string) ($claims['nonce'] ?? '');

            if ($actualNonce === '' || ! hash_equals($expectedNonce, $actualNonce)) {
                throw new InvalidProviderTokenException('Google ID token nonce mismatch.');
            }
        }

        $this->verifiedIdTokenClaims = $claims;

        return $claims;
    }

    /**
     * The override above throws firebase/php-jwt exceptions unwrapped, so the
     * temporal failure is usually the caught exception itself. The chain is
     * still walked because a parent implementation (or a future Socialite
     * change) may wrap it several levels down.
     *
     * @param  class-string<\Throwable>  $class
     */
    private function findException(\Throwable $exception, string $class): ?\Throwable
    {
        do {
            if ($exception instanceof $class) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }
}
