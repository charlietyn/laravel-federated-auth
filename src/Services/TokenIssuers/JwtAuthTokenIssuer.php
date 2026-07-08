<?php

namespace Ronu\LaravelFederatedAuth\Services\TokenIssuers;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\Exceptions\TokenIssuerNotAvailableException;
use Throwable;

class JwtAuthTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        $guard = $context->guard ?: config('auth.defaults.guard', 'api');
        $auth = auth($guard);

        if (! method_exists($auth, 'login')) {
            throw new TokenIssuerNotAvailableException("Guard [$guard] does not support login(user).");
        }

        $token = $auth->login($user);
        $tokens = [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'bearer',
        ];

        if (method_exists($auth, 'factory')) {
            $tokens['expires_in'] = $auth->factory()->getTTL();
        }

        if (class_exists(JWTAuth::class)) {
            try {
                $tokens['refresh_token'] = app(JWTAuth::class)
                    ->claims(['refresh' => true])
                    ->fromUser($user);
                $tokens['refresh_expires_in'] = (int) config('jwt.refresh_ttl');
            } catch (Throwable) {
            }
        }

        return new AuthResult($user, $tokens);
    }
}
