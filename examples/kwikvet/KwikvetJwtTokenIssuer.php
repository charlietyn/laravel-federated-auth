<?php
namespace App\Auth;
use Illuminate\Contracts\Auth\Authenticatable; use PHPOpenSourceSaver\JWTAuth\JWTAuth; use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\AuthResult;
class KwikvetJwtTokenIssuer implements TokenIssuerInterface { public function issue(Authenticatable $user, AuthContext $context): AuthResult { $guard=$context->guard?:'api'; $token=auth($guard)->login($user); return new AuthResult($user,['token'=>$token,'access_token'=>$token,'token_type'=>'bearer','expires_in'=>auth($guard)->factory()->getTTL(),'refresh_token'=>app(JWTAuth::class)->claims(['refresh'=>true])->fromUser($user),'refresh_expires_in'=>(int)config('jwt.refresh_ttl')]); } }
