<?php
namespace Ronu\LaravelFederatedAuth\Tests\Unit;
use Ronu\LaravelFederatedAuth\DTO\ExternalIdentity; use Ronu\LaravelFederatedAuth\Tests\TestCase;
class ExternalIdentityTest extends TestCase { public function test_it_detects_required_missing_email(): void { $identity=new ExternalIdentity(provider:'google',providerUserId:'123',email:null); $this->assertTrue($identity->requiresEmailButMissing(true)); $this->assertFalse($identity->requiresEmailButMissing(false)); } }
