<?php
namespace Ronu\LaravelFederatedAuth\Services;
use Illuminate\Contracts\Auth\Authenticatable; use Ronu\LaravelFederatedAuth\Contracts\UserStatusCheckerInterface; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\Exceptions\UserDisabledException;
class DefaultUserStatusChecker implements UserStatusCheckerInterface { public function ensureCanLogin(Authenticatable $user, AuthContext $context): void { $col=config('federated-auth.user.columns.status'); if(!$col) return; $status=data_get($user,$col); if($status===null) return; if(!in_array($status,config('federated-auth.user.active_status_values',[1,'1',true,'active','enabled']),true)) throw new UserDisabledException('The local user is not active.'); } }
