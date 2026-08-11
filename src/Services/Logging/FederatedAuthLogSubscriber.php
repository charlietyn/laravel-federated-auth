<?php

namespace Ronu\LaravelFederatedAuth\Services\Logging;

use Illuminate\Support\Facades\Log;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\Events\ExternalAccountLinked;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginFailed;
use Ronu\LaravelFederatedAuth\Events\ExternalLoginSucceeded;
use Ronu\LaravelFederatedAuth\Events\ExternalRedirectIssued;
use Ronu\LaravelFederatedAuth\Events\ExternalUserProvisioned;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;

/**
 * Optional structured trace of every federated authentication flow.
 *
 * Disabled by default (`federated-auth.logging.enabled`) so upgrading never
 * starts writing to an application's log without it asking. Host applications
 * that want their own shape can leave this off and listen to the same events
 * directly — this class exists so that the common case does not have to.
 *
 * ## What is deliberately never logged
 *
 * The raw OAuth state, authorization codes, id/access/refresh tokens, client
 * secrets, and the request URL (which carries the code on the callback leg).
 * Only a truncated, non-reversible digest of the state is recorded, which is
 * what allows the redirect line and the callback line of the same attempt to be
 * joined without leaving a replayable token in the log.
 *
 * Email addresses are personal data and are logged only when the host opts in
 * via `federated-auth.logging.include_email`.
 */
class FederatedAuthLogSubscriber
{
    public function handleRedirectIssued(ExternalRedirectIssued $event): void
    {
        $this->write('info', 'redirect_issued', $event->context, [
            'state_digest' => $event->stateDigest,
        ]);
    }

    public function handleLoginSucceeded(ExternalLoginSucceeded $event): void
    {
        $this->write('info', 'login_succeeded', $event->context, [
            'user_id' => $event->user->getAuthIdentifier(),
            'was_provisioned' => $event->result->wasProvisioned,
            'was_linked' => $event->result->wasLinked,
            'email' => $this->email($event->identity->email),
        ]);
    }

    public function handleLoginFailed(ExternalLoginFailed $event): void
    {
        // The exception CLASS is recorded, never its message: provider errors
        // routinely embed the authorization code or a token fragment.
        $this->write('warning', 'login_failed', $event->context, [
            'exception' => $event->exception::class,
            'email' => $this->email($event->identity?->email),
        ]);
    }

    public function handleUserProvisioned(ExternalUserProvisioned $event): void
    {
        $this->write('info', 'user_provisioned', $event->context, [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    public function handleAccountLinked(ExternalAccountLinked $event): void
    {
        $this->write('info', 'account_linked', $event->context, [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            ExternalRedirectIssued::class => 'handleRedirectIssued',
            ExternalLoginSucceeded::class => 'handleLoginSucceeded',
            ExternalLoginFailed::class => 'handleLoginFailed',
            ExternalUserProvisioned::class => 'handleUserProvisioned',
            ExternalAccountLinked::class => 'handleAccountLinked',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function write(string $level, string $event, AuthContext $context, array $extra = []): void
    {
        $level = (string) config("federated-auth.logging.levels.{$event}", $level);
        // Falling back to the application's default channel rather than forcing
        // one: the host already decided where its logs go.
        $channel = config('federated-auth.logging.channel');
        $logger = is_string($channel) && $channel !== ''
            ? Log::channel($channel)
            : Log::getFacadeRoot();

        $logger->log($level, "federated-auth: {$event}", array_filter([
            'event' => "federated_auth_{$event}",
            'provider' => $context->provider,
            'channel' => $context->channel,
            'user_type' => $context->userType,
            'tenant_id' => $context->tenantId,
            'state_digest' => $extra['state_digest']
                ?? OAuthSecurity::stateDigest($context->state),
            ...$extra,
        ], static fn ($value): bool => $value !== null));
    }

    private function email(?string $email): ?string
    {
        return (bool) config('federated-auth.logging.include_email', false) ? $email : null;
    }
}
