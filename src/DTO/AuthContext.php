<?php
namespace Ronu\LaravelFederatedAuth\DTO;
use Illuminate\Http\Request;
final class AuthContext { public function __construct(public readonly string $provider, public readonly ?Request $request=null, public readonly ?string $guard=null, public readonly ?string $tenantId=null, public readonly ?string $userType=null, public readonly ?string $channel=null, public readonly ?string $redirectUri=null, public readonly ?string $state=null, public readonly array $metadata=[]) {}
 public static function fromRequest(string $provider, Request $request): self { return new self($provider,$request,$request->input('guard'),$request->input('tenant_id')??$request->header('X-Tenant-Id'),$request->input('user_type'),$request->input('channel')??$request->header('X-Channel'),$request->input('redirect_uri'),$request->input('state')??$request->query('state'),['ip'=>$request->ip(),'user_agent'=>$request->userAgent()]); }
}
