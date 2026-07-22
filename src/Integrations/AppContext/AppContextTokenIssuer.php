<?php

namespace Ronu\LaravelFederatedAuth\Integrations\AppContext;

use Illuminate\Contracts\Auth\Authenticatable;
use Ronu\AppContext\Context\AppContext;
use Ronu\AppContext\Contracts\ContextTokenIssuerInterface;
use Ronu\LaravelFederatedAuth\Contracts\TokenIssuerInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\Exceptions\TokenIssuerNotAvailableException;

/**
 * Issues channel-bound JWTs for hosts running ronu/laravel-app-context.
 *
 * The stock JwtAuthTokenIssuer emits a bare JWT with no channel audience. Under
 * app-context that token authenticates nowhere: AuthenticateChannel rejects a
 * token whose `aud` does not match the resolved channel, and
 * EnforceContextBinding additionally checks `tid` and `did`. This issuer
 * delegates minting to app-context itself, so a federated login and a local
 * password login emit interchangeable tokens.
 *
 * Bind it by pointing `federated-auth.bindings` at this class. It is not a
 * default: the package must keep working without app-context installed.
 */
class AppContextTokenIssuer implements TokenIssuerInterface
{
    public function __construct(private readonly ContextTokenIssuerInterface $issuer) {}

    public function issue(Authenticatable $user, AuthContext $context): AuthResult
    {
        $appContext = $this->resolveAppContext();

        $tokens = $this->issuer->issueFor($user, $appContext, $context->request);

        return new AuthResult(
            user: $user,
            tokens: array_merge($tokens, [
                'channel' => $appContext->getAppId(),
                'tenant_id' => $appContext->getTenantId(),
            ]),
        );
    }

    /**
     * The AppContext singleton is bound to null until ResolveAppContext runs, so
     * it has to be pulled from the container per request rather than injected.
     *
     * A null value means the federated routes were registered outside the
     * context pipeline, which would otherwise mint a token with no audience that
     * fails on the next request with a confusing 401. Fail loudly here instead.
     */
    private function resolveAppContext(): AppContext
    {
        $appContext = app(AppContext::class);

        if (! $appContext instanceof AppContext) {
            throw new TokenIssuerNotAvailableException(
                'No application context resolved. Federated auth routes must run behind the '
                .'app-context middleware (for example "ctx.resolve") so the issued JWT is bound '
                .'to a channel.'
            );
        }

        return $appContext;
    }
}
