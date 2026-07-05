<?php
namespace App\Auth;
use App\Models\User; use Illuminate\Contracts\Auth\Authenticatable; use Illuminate\Support\Facades\Hash; use Illuminate\Support\Str; use Ronu\LaravelFederatedAuth\Contracts\UserProvisionerInterface; use Ronu\LaravelFederatedAuth\DTO\AuthContext; use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity;
class StandardUserProvisioner implements UserProvisionerInterface { public function provision(ExternalIdentity $identity, AuthContext $context): Authenticatable { return User::create(['name'=>$identity->name?:$identity->email,'email'=>$identity->email,'password'=>Hash::make(Str::random(64))]); } }
