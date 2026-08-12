<?php

namespace Ronu\LaravelFederatedAuth\Support;

use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;

/**
 * Removes replayable OAuth material from anything on its way to durable storage.
 *
 * An error log is the one place where the package's own rule — never write a
 * token, a code or a state anywhere it can be read back — is easiest to break by
 * accident. Provider SDKs routinely put the authorization code in the exception
 * message, Guzzle puts the full request URL (query string included) in it, and a
 * dump of `$request->all()` on the callback leg contains `code` and `state`
 * verbatim. A row in an error table outlives the 60-second window in which those
 * values are useful to an attacker only if nobody ever reads the table.
 *
 * So every string and every array that leaves {@see FederatedAuthError}
 * passes through here first. This is not configurable-off on purpose: the host
 * can add keys, never remove the defaults.
 */
final class SensitiveDataScrubber
{
    public const REDACTED = '[REDACTED]';

    /**
     * Keys whose values are never written, whatever the host configures.
     *
     * @var array<int, string>
     */
    private const ALWAYS_SENSITIVE = [
        'code',
        'state',
        'code_verifier',
        'code_challenge',
        'nonce',
        'id_token',
        'access_token',
        'refresh_token',
        'provider_token',
        'token',
        'client_secret',
        'client_assertion',
        'assertion',
        'secret',
        'private_key',
        'password',
        'password_confirmation',
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-csrf-token',
        'x-xsrf-token',
        'api_key',
        'apikey',
    ];

    /**
     * Redact the values of sensitive keys, recursively.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrubArray(array $data, ?array $keys = null): array
    {
        $keys ??= self::sensitiveKeys();
        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = match (true) {
                is_array($value) => self::scrubArray($value, $keys),
                is_string($value) => self::scrubString($value),
                default => $value,
            };
        }

        return $scrubbed;
    }

    /**
     * Redact credential-shaped substrings inside free text.
     *
     * Applied to exception messages, stack frames and URLs, where the secret is
     * embedded in prose rather than sitting under a key of its own.
     */
    public static function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // A JWT is self-identifying: three base64url segments after the `eyJ`
        // that every `{"alg"...}` header encodes to. Matched first so that an
        // id_token pasted bare into a message is caught even without a key.
        $value = (string) preg_replace(
            '/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}(?:\.[A-Za-z0-9_-]*)?/',
            self::REDACTED,
            $value
        );

        $value = (string) preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]{8,}={0,2}/i',
            '$1 '.self::REDACTED,
            $value
        );

        // Query-string and form shapes: `?code=4/0Ade…`, `&state=abc`.
        $value = (string) preg_replace_callback(
            '/\b([A-Za-z0-9_-]+)=([^&\s"\'<>]+)/',
            static fn (array $m): string => in_array(strtolower($m[1]), self::sensitiveKeys(), true)
                ? $m[1].'='.self::REDACTED
                : $m[0],
            $value
        );

        // JSON shapes: `"access_token":"ya29…"`.
        return (string) preg_replace_callback(
            '/"([A-Za-z0-9_-]+)"\s*:\s*"([^"]*)"/',
            static fn (array $m): string => in_array(strtolower($m[1]), self::sensitiveKeys(), true)
                ? '"'.$m[1].'":"'.self::REDACTED.'"'
                : $m[0],
            $value
        );
    }

    /**
     * The always-sensitive defaults plus any host additions.
     *
     * @return array<int, string>
     */
    public static function sensitiveKeys(): array
    {
        $configured = config('federated-auth.error_reporting.payload.sensitive_keys', []);
        $extra = is_array($configured) ? array_filter($configured, 'is_string') : [];

        return array_values(array_unique(array_map(
            'strtolower',
            [...self::ALWAYS_SENSITIVE, ...$extra]
        )));
    }
}
