<?php

/*
|--------------------------------------------------------------------------
| Superseded by the app-context integration
|--------------------------------------------------------------------------
|
| This example used to be the recommended issuer for hosts running
| ronu/laravel-app-context. It is not: the token it emits carries no `aud`,
| `tid` or `did`, which AuthenticateChannel and EnforceContextBinding reject —
| so the login succeeds and every subsequent request comes back 401.
|
| Install ronu/laravel-app-context and bind the shipped integration instead.
| It delegates minting to app-context, which owns the claim contract:
|
|   // config/federated-auth.php
|   use Ronu\LaravelFederatedAuth\Integrations\AppContext\AppContextTokenIssuer;
|
|   'bindings' => [
|       TokenIssuerInterface::class => AppContextTokenIssuer::class,
|   ],
|
| Nothing else is needed: AppContextTokenIssuer resolves the request's
| AppContext itself and fails loudly if the route is not behind the
| context-resolution middleware.
|
| The class below is kept only as a reference for hosts that do NOT use
| app-context and want a minimal custom issuer. Do not copy it into an
| app-context project.
|
*/

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;

class RonuJwtTokenIssuer implements TokenIssuerInterface
{
    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        $guard = $context->guard ?: 'api';
        $token = auth($guard)->login($user);

        return new AuthResult($user, [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'bearer',
            // getTTL() is in minutes; `expires_in` is defined in seconds by
            // RFC 6749 §5.1, so it has to be converted.
            'expires_in' => auth($guard)->factory()->getTTL() * 60,
            'refresh_token' => app(JWTAuth::class)->claims(['refresh' => true])->fromUser($user),
            'refresh_expires_in' => (int) config('jwt.refresh_ttl') * 60,
        ]);
    }
}
