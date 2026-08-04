<?php

namespace Ronu\LaravelFederatedAuth\Support;

use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderNotSupportedException;

final class ProviderConfig
{
    /**
     * Resolve provider configuration and, when configured, overlay the OAuth
     * client that belongs to the trusted application channel restored from
     * server-side state.
     */
    public static function get(string $provider, ?AuthContext $context = null): array
    {
        $config = config("federated-auth.providers.$provider");

        if (! is_array($config)) {
            throw new ProviderNotSupportedException("Provider [$provider] is not configured.");
        }

        if (! ($config['enabled'] ?? false)) {
            throw new ProviderDisabledException("Provider [$provider] is disabled.");
        }

        $clients = $config['clients'] ?? [];
        $channel = $context?->channel;
        $usesChannelClients = is_array($clients) && $clients !== [];

        if ($usesChannelClients) {
            if (! is_string($channel) || $channel === '') {
                throw new ProviderDisabledException(
                    "Provider [$provider] requires a trusted application channel."
                );
            }

            $client = $clients[$channel] ?? null;

            if (! is_array($client) || ! ($client['enabled'] ?? true)) {
                throw new ProviderDisabledException(
                    "No enabled OAuth client is configured for provider [$provider] and channel [$channel]."
                );
            }

            $config = array_replace($config, $client);
            $config['resolved_channel'] = $channel;
        }

        // Channel-client mode is fail-closed. Legacy flat provider
        // configurations remain backwards compatible unless they explicitly
        // set require_client_id=true.
        $requiresClientId = (bool) ($config['require_client_id'] ?? $usesChannelClients);

        if ($requiresClientId && blank($config['client_id'] ?? null)) {
            throw new ProviderDisabledException(
                "Provider [$provider] does not have a client_id for the resolved channel."
            );
        }

        return $config;
    }

    public static function value(
        string $provider,
        string $key,
        mixed $default = null,
        ?AuthContext $context = null,
    ): mixed {
        $config = self::get($provider, $context);

        return $config[$key] ?? $default;
    }
}
