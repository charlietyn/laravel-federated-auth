<?php
namespace Ronu\LaravelFederatedAuth\Services\TokenIssuers;
use Illuminate\Contracts\Auth\Authenticatable; use Illuminate\Support\Facades\Auth; use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\AuthResult;
class SessionTokenIssuer implements TokenIssuerInterface { public function issue(Authenticatable $user, AuthContext $context): AuthResult { Auth::guard($context->guard?:'web')->login($user); return new AuthResult($user,['token_type'=>'session']); } }
