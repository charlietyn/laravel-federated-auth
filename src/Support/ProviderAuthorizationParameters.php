<?php

namespace Ronu\LaravelFederatedAuth\Support;

final class ProviderAuthorizationParameters
{
    /**
     * Parameters owned by the provider adapter or by the OAuth transaction.
     * Host configuration must never be able to replace them.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'client_id',
        'client_secret',
        'redirect_uri',
        'response_type',
        'response_mode',
        'scope',
        'state',
        'nonce',
        'code_challenge',
        'code_challenge_method',
    ];

    /**
     * Return safe scalar parameters supplied by server-side configuration.
     *
     * @return array<string, bool|float|int|string>
     */
    public static function sanitize(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $reserved = array_fill_keys(self::RESERVED, true);
        $parameters = [];

        foreach ($configured as $name => $value) {
            if (
                ! is_string($name)
                || preg_match('/^[A-Za-z][A-Za-z0-9_.~-]*$/', $name) !== 1
                || isset($reserved[strtolower($name)])
                || ! is_scalar($value)
                || (is_string($value) && trim($value) === '')
            ) {
                continue;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
