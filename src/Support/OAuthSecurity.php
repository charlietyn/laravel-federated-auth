<?php

namespace Ronu\LaravelFederatedAuth\Support;

use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidRedirectUriException;

final class OAuthSecurity
{
    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    public static function codeVerifier(): string
    {
        return self::randomToken(64);
    }

    public static function codeChallenge(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function fingerprint(Request $request, bool $bindUserAgent = true, bool $bindIp = false): array
    {
        $fingerprint = [];

        if ($bindUserAgent) {
            $fingerprint['user_agent_hash'] = hash('sha256', (string) $request->userAgent());
        }

        if ($bindIp) {
            $fingerprint['ip_hash'] = hash('sha256', (string) $request->ip());
        }

        return $fingerprint;
    }

    public static function validateRedirectUri(?string $requested, ?string $fallback): ?string
    {
        $redirectUri = $requested ?: $fallback;

        if ($redirectUri === null || $redirectUri === '') {
            return $redirectUri;
        }

        $parts = parse_url($redirectUri);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidRedirectUriException('Redirect URI must be an absolute URI with scheme and host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidRedirectUriException('Redirect URI must not contain userinfo.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $allowedHosts = self::allowedRedirectHosts();
        $allowLocalHttp = (bool) config('federated-auth.security.redirects.allow_http_localhost', false);
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https' && ! ($allowLocalHttp && $scheme === 'http' && $isLocalhost)) {
            throw new InvalidRedirectUriException('Redirect URI must use HTTPS.');
        }

        if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
            throw new InvalidRedirectUriException("Redirect URI host [$host] is not allowed.");
        }

        return $redirectUri;
    }

    public static function allowedRedirectHosts(): array
    {
        $configured = config('federated-auth.security.redirects.allowed_hosts', []);

        if (is_string($configured)) {
            $configured = array_filter(array_map('trim', explode(',', $configured)));
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $host): string => strtolower($host),
            array_filter($configured, static fn ($host): bool => is_string($host) && $host !== '')
        )));
    }
}
