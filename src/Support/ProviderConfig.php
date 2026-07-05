<?php
namespace Ronu\LaravelFederatedAuth\Support;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException; use Ronu\LaravelFederatedAuth\Exceptions\ProviderNotSupportedException;
final class ProviderConfig { public static function get(string $provider): array { $config=config("federated-auth.providers.$provider"); if(!is_array($config)) throw new ProviderNotSupportedException("Provider [$provider] is not configured."); if(!($config['enabled']??false)) throw new ProviderDisabledException("Provider [$provider] is disabled."); return $config; } public static function value(string $provider,string $key,mixed $default=null): mixed { $config=self::get($provider); return $config[$key]??$default; } }
