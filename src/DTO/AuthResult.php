<?php
namespace Ronu\LaravelFederatedAuth\DTO;
use Illuminate\Contracts\Auth\Authenticatable;
final class AuthResult { public function __construct(public readonly Authenticatable $user, public readonly array $tokens=[], public readonly ?ExternalIdentity $externalIdentity=null, public readonly bool $wasProvisioned=false, public readonly bool $wasLinked=false, public readonly array $metadata=[]) {} public function toArray(): array { return array_merge(['success'=>true,'user'=>$this->user,'was_provisioned'=>$this->wasProvisioned,'was_linked'=>$this->wasLinked],$this->tokens,['metadata'=>$this->metadata]); } }
