<?php

namespace Ronu\LaravelFederatedAuth\Providers;

use Illuminate\Support\Arr;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;

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

    private function verifiedClaims(
        string $idToken,
        ?string $expectedNonce,
    ): array {
        try {
            // GoogleProvider verifies the signature, issuer, configured client
            // audience and JWT temporal claims through firebase/php-jwt/JWKS.
            $claims = $this->getUserFromJwtToken($idToken);
        } catch (\Throwable $exception) {
            throw new InvalidProviderTokenException(
                'Google ID token verification failed.',
                previous: $exception,
            );
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
}
