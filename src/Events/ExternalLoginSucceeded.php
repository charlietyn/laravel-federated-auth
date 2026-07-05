<?php
namespace Ronu\LaravelFederatedAuth\Events;
class ExternalLoginSucceeded { public function __construct(public readonly \Illuminate\Contracts\Auth\Authenticatable $user, public readonly \Ronu\LaravelFederatedAuth\DTO\ExternalIdentity $identity, public readonly \Ronu\LaravelFederatedAuth\DTO\AuthContext $context, public readonly \Ronu\LaravelFederatedAuth\DTO\AuthResult $result) {} }
