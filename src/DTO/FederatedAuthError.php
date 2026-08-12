<?php

namespace Ronu\LaravelFederatedAuth\DTO;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\Exceptions\AmbiguousLocalUserException;
use Ronu\LaravelFederatedAuth\Exceptions\EmailNotVerifiedException;
use Ronu\LaravelFederatedAuth\Exceptions\EmailRequiredException;
use Ronu\LaravelFederatedAuth\Exceptions\IdentityAlreadyLinkedException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOidcTokenException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidRedirectUriException;
use Ronu\LaravelFederatedAuth\Exceptions\LastIdentityUnlinkDeniedException;
use Ronu\LaravelFederatedAuth\Exceptions\PackageDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\ProviderNotSupportedException;
use Ronu\LaravelFederatedAuth\Exceptions\UserDisabledException;
use Ronu\LaravelFederatedAuth\Exceptions\UserProvisioningNotConfiguredException;
use Ronu\LaravelFederatedAuth\Support\OAuthSecurity;
use Ronu\LaravelFederatedAuth\Support\SensitiveDataScrubber;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A federated authentication failure, normalized for durable storage.
 *
 * The broker builds one of these for every operation that throws and hands it to
 * the configured {@see ErrorReporterInterface}.
 * It carries the live objects (exception, context, identity) for hosts that want
 * to inspect them, and {@see toArray()} flattens the same failure into a scalar
 * row for hosts that just want to insert it.
 *
 * Everything {@see toArray()} emits has passed through {@see SensitiveDataScrubber}.
 */
final class FederatedAuthError
{
    public const OPERATION_REDIRECT = 'redirect';

    public const OPERATION_LOGIN_CALLBACK = 'login_callback';

    public const OPERATION_LOGIN_TOKEN = 'login_token';

    public const OPERATION_LINK = 'link';

    public const OPERATION_UNLINK = 'unlink';

    /**
     * Package exceptions carry no HTTP status of their own — the host's exception
     * handler decides that. This is the package's opinion about what each domain
     * failure means, so an error row is triageable without re-reading the class.
     *
     * @var array<class-string, int>
     */
    private const STATUS_CODES = [
        InvalidOAuthStateException::class => 401,
        InvalidOidcTokenException::class => 401,
        InvalidProviderTokenException::class => 401,
        EmailNotVerifiedException::class => 401,
        InvalidRedirectUriException::class => 400,
        PackageDisabledException::class => 403,
        ProviderDisabledException::class => 403,
        UserDisabledException::class => 403,
        ProviderNotSupportedException::class => 404,
        EmailRequiredException::class => 422,
        AmbiguousLocalUserException::class => 422,
        IdentityAlreadyLinkedException::class => 409,
        LastIdentityUnlinkDeniedException::class => 409,
        UserProvisioningNotConfiguredException::class => 403,
    ];

    public function __construct(
        public readonly string $operation,
        public readonly Throwable $exception,
        public readonly AuthContext $context,
        public readonly ?ExternalIdentity $identity = null,
        public readonly ?Authenticatable $user = null,
    ) {}

    /**
     * The exception message, with any embedded credential redacted.
     *
     * Never read `$error->exception->getMessage()` directly on the way to
     * storage — provider errors quote the authorization code and the callback
     * URL back at you.
     */
    public function message(): string
    {
        return SensitiveDataScrubber::scrubString($this->exception->getMessage());
    }

    public function statusCode(): int
    {
        if ($this->exception instanceof HttpExceptionInterface) {
            return $this->exception->getStatusCode();
        }

        foreach (self::STATUS_CODES as $class => $status) {
            if ($this->exception instanceof $class) {
                return $status;
            }
        }

        return 500;
    }

    /**
     * Flatten the failure into a storable row.
     *
     * Key names deliberately match the columns an application error-log table
     * usually already has (`description`, `error`, `path`, `ip`, `status_code`,
     * `parameters`, `request`, `headers`, `user_id`, `username`) so a host job
     * can insert the array as-is, with the federated-specific columns
     * (`provider`, `channel`, `tenant_id`, `operation`, `state_digest`) trimmed
     * off by `error_reporting.payload.only` when the table does not have them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $request = $this->request();
        $payload = [
            'description' => $this->description(),
            'error' => $this->message(),
            'exception' => $this->exception::class,
            'code' => $this->exception->getCode(),
            'file' => $this->exception->getFile(),
            'line' => $this->exception->getLine(),
            'status_code' => $this->statusCode(),

            'operation' => $this->operation,
            'provider' => $this->context->provider,
            'channel' => $this->context->channel,
            'user_type' => $this->context->userType,
            'tenant_id' => $this->context->tenantId,
            'guard' => $this->context->guard,
            // Joins this row to the redirect leg in the application log without
            // storing the replayable state itself.
            'state_digest' => OAuthSecurity::stateDigest($this->context->state),
            'provider_user_id' => $this->identity?->providerUserId,
            'email' => $this->email(),

            'ip' => $this->ip($request),
            'path' => $request?->path(),
            'parameters' => $this->encode($this->parameters($request)),
            'request' => $this->encode($this->requestSummary($request)),
            'headers' => $this->encode($this->headers($request)),

            'user_id' => $this->userId(),
            'username' => $this->username(),

            'created_at' => now(),
            'updated_at' => now(),
        ];

        return $this->only($payload);
    }

    /**
     * Human-readable summary, formatted for a wide text column.
     */
    public function description(): string
    {
        $parts = [
            'Federated auth failure',
            'Operation: '.$this->operation,
            'Provider: '.$this->context->provider,
            'Type: '.$this->exception::class,
            'Status: '.$this->statusCode(),
            '',
            'MESSAGE:',
            $this->message(),
            '',
            'LOCATION:',
            'File: '.$this->exception->getFile(),
            'Line: '.$this->exception->getLine(),
        ];

        if ($previous = $this->exception->getPrevious()) {
            $parts[] = '';
            $parts[] = 'PREVIOUS:';
            $parts[] = $previous::class.': '.SensitiveDataScrubber::scrubString($previous->getMessage());
        }

        if ($trace = $this->trace()) {
            $parts[] = '';
            $parts[] = 'STACK TRACE:';
            $parts[] = $trace;
        }

        return implode("\n", $parts);
    }

    /**
     * The first N stack frames, scrubbed. Frame *arguments* are never included —
     * the provider token is an argument to half the frames in this package.
     */
    private function trace(): ?string
    {
        if (! (bool) config('federated-auth.error_reporting.payload.include_trace', true)) {
            return null;
        }

        $limit = max(1, (int) config('federated-auth.error_reporting.payload.trace_frames', 15));
        $frames = [];

        foreach (array_slice($this->exception->getTrace(), 0, $limit) as $index => $frame) {
            $frames[] = sprintf(
                '#%d %s(%s): %s%s%s()',
                $index,
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '0',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            );
        }

        return $frames === [] ? null : SensitiveDataScrubber::scrubString(implode("\n", $frames));
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(?Request $request): array
    {
        if (! $request || ! (bool) config('federated-auth.error_reporting.payload.include_request', true)) {
            return [];
        }

        return SensitiveDataScrubber::scrubArray($request->except(
            SensitiveDataScrubber::sensitiveKeys()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSummary(?Request $request): array
    {
        if (! $request || ! (bool) config('federated-auth.error_reporting.payload.include_request', true)) {
            return [];
        }

        return [
            'method' => $request->method(),
            // fullUrl() carries `?code=…&state=…` on the callback leg.
            'url' => SensitiveDataScrubber::scrubString($request->fullUrl()),
            'query' => SensitiveDataScrubber::scrubArray($request->query()),
            'user_agent' => $request->userAgent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(?Request $request): array
    {
        if (! $request || ! (bool) config('federated-auth.error_reporting.payload.include_headers', true)) {
            return [];
        }

        $headers = array_map(
            static fn ($value) => is_array($value) ? implode(', ', $value) : $value,
            $request->headers->all()
        );

        return SensitiveDataScrubber::scrubArray($headers);
    }

    /**
     * Email is personal data, and on Apple it is a private-relay address that
     * identifies the user to the provider. Opt in explicitly.
     */
    private function email(): ?string
    {
        return (bool) config('federated-auth.error_reporting.payload.include_email', false)
            ? $this->identity?->email
            : null;
    }

    private function userId(): int|string|null
    {
        if ($this->user) {
            return $this->user->getAuthIdentifier();
        }

        try {
            return auth()->id();
        } catch (Throwable) {
            // No guard resolvable (queue worker, console) — not worth failing over.
            return null;
        }
    }

    private function username(): ?string
    {
        try {
            $user = $this->user ?? auth()->user();
        } catch (Throwable) {
            return null;
        }

        if (! $user) {
            return null;
        }

        return $user->username ?? $user->email ?? $user->name ?? null;
    }

    private function request(): ?Request
    {
        return $this->context->request;
    }

    private function ip(?Request $request): ?string
    {
        $ip = $request?->ip() ?? ($this->context->metadata['ip'] ?? null);

        return is_string($ip) ? $ip : null;
    }

    /**
     * Arrays are stored as JSON so the payload stays insertable into text columns.
     *
     * Empty and unencodable values become `{}` rather than null: an existing
     * error table commonly declares these columns NOT NULL, and "nothing was
     * captured" is a truthful thing for an empty JSON object to say. A host that
     * would rather store null can coalesce in its own reporter.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{}';
    }

    /**
     * Restrict the payload to the columns the host's table actually has.
     *
     * A model with a strict `$fillable` throws on an unknown key, so a host
     * pointing this at an existing error table needs a way to trim rather than
     * to remap.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function only(array $payload): array
    {
        $only = config('federated-auth.error_reporting.payload.only', []);

        if (! is_array($only) || $only === []) {
            return $payload;
        }

        return array_intersect_key($payload, array_flip(array_filter($only, 'is_string')));
    }
}
