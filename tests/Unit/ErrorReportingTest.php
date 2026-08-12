<?php

namespace Ronu\LaravelFederatedAuth\Tests\Unit;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Ronu\LaravelFederatedAuth\Contracts\ErrorReporterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderAdapterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\FederatedAuthError;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidOAuthStateException;
use Ronu\LaravelFederatedAuth\Exceptions\InvalidProviderTokenException;
use Ronu\LaravelFederatedAuth\Services\Errors\NullErrorReporter;
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;
use Ronu\LaravelFederatedAuth\Support\SensitiveDataScrubber;
use Ronu\LaravelFederatedAuth\Tests\TestCase;
use RuntimeException;
use Throwable;

class ErrorReportingTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    public static array $captured = [];

    /** @var array<int, FederatedAuthError> */
    public static array $capturedErrors = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$captured = [];
        self::$capturedErrors = [];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('federated-auth.enabled', true);
        $app['config']->set('federated-auth.providers.google', [
            'enabled' => true,
            'driver' => 'socialite',
            'socialite_driver' => 'google',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://app.example.com/callback',
        ]);
    }

    private function enable(mixed $handlers): void
    {
        config()->set('federated-auth.error_reporting.enabled', true);
        config()->set('federated-auth.error_reporting.handlers', (array) $handlers);
    }

    private function swapAdapter(IdentityProviderAdapterInterface $adapter): void
    {
        $registry = Mockery::mock(IdentityProviderRegistryInterface::class);
        $registry->shouldReceive('adapterFor')->andReturn($adapter);
        $this->app->instance(IdentityProviderRegistryInterface::class, $registry);
    }

    private function context(?Request $request = null): AuthContext
    {
        return new AuthContext(
            provider: 'google',
            request: $request ?? Request::create('/auth/google/callback'),
            tenantId: 'tenant-7',
            userType: 'Client',
            channel: 'admin',
            state: 'the-real-state',
        );
    }

    /**
     * Drive a token login that is guaranteed to fail, and return the captured rows.
     */
    private function failLogin(Throwable|string $exception = 'bad token'): void
    {
        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('userFromToken')->andThrow(
            is_string($exception) ? new InvalidProviderTokenException($exception) : $exception
        );
        $this->swapAdapter($adapter);

        try {
            $this->app->make(FederatedAuthBroker::class)
                ->loginFromToken('google', 'forged-token', $this->context());
        } catch (Throwable) {
            // Expected — capture must not swallow the authentication error.
        }
    }

    // -----------------------------------------------------------------------
    // Defaults
    // -----------------------------------------------------------------------

    public function test_error_reporting_is_off_by_default(): void
    {
        // Upgrading the package must never start writing rows into a host's
        // database unasked, exactly like the log subscriber.
        $this->assertFalse(config('federated-auth.error_reporting.enabled'));

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }

    public function test_no_handlers_configured_captures_nothing(): void
    {
        config()->set('federated-auth.error_reporting.enabled', true);

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }

    // -----------------------------------------------------------------------
    // Handler shapes
    // -----------------------------------------------------------------------

    public function test_a_closure_handler_receives_the_payload_and_the_dto(): void
    {
        $this->enable([function (array $payload, FederatedAuthError $error): void {
            self::$captured[] = $payload;
            self::$capturedErrors[] = $error;
        }]);

        $this->failLogin();

        $this->assertCount(1, self::$captured);
        $row = self::$captured[0];

        $this->assertSame(FederatedAuthError::OPERATION_LOGIN_TOKEN, $row['operation']);
        $this->assertSame('google', $row['provider']);
        $this->assertSame('admin', $row['channel']);
        $this->assertSame('tenant-7', $row['tenant_id']);
        $this->assertSame('Client', $row['user_type']);
        $this->assertSame(InvalidProviderTokenException::class, $row['exception']);
        $this->assertSame(401, $row['status_code']);
        $this->assertStringContainsString('bad token', $row['error']);
        $this->assertStringContainsString('MESSAGE:', $row['description']);

        $this->assertInstanceOf(FederatedAuthError::class, self::$capturedErrors[0]);
    }

    public function test_a_class_implementing_the_contract_receives_the_dto(): void
    {
        $this->enable([RecordingReporter::class]);

        $this->failLogin();

        $this->assertCount(1, self::$capturedErrors);
        $this->assertInstanceOf(
            InvalidProviderTokenException::class,
            self::$capturedErrors[0]->exception
        );
    }

    public function test_a_queued_job_handler_is_dispatched_with_the_payload(): void
    {
        Queue::fake();
        config()->set('federated-auth.error_reporting.queue.queue', 'logs');
        $this->enable([RecordingJob::class]);

        $this->failLogin();

        Queue::assertPushedOn('logs', RecordingJob::class, function (RecordingJob $job): bool {
            $this->assertSame('google', $job->errorData['provider']);
            $this->assertSame(
                FederatedAuthError::OPERATION_LOGIN_TOKEN,
                $job->errorData['operation']
            );

            return true;
        });
    }

    public function test_a_queued_job_without_the_queueable_trait_is_still_dispatched(): void
    {
        // ShouldQueue does not imply Queueable, so routing must be skipped
        // rather than fataling on a missing onQueue().
        Queue::fake();
        $this->enable([BareQueuedJob::class]);

        $this->failLogin();

        Queue::assertPushed(BareQueuedJob::class);
    }

    public function test_a_service_at_method_handler_is_resolved_and_called(): void
    {
        // The shape that lets an existing service class be reused verbatim.
        $this->enable([RecordingService::class.'@store']);

        $this->failLogin();

        $this->assertCount(1, self::$captured);
        $this->assertSame('google', self::$captured[0]['provider']);
    }

    public function test_an_invokable_handler_that_only_declares_the_payload_still_works(): void
    {
        // A host method written as handle(array $data) must not have to grow a
        // second parameter just because the package passes the DTO along.
        $this->enable([PayloadOnlyHandler::class]);

        $this->failLogin();

        $this->assertCount(1, self::$captured);
    }

    public function test_every_configured_handler_receives_the_error(): void
    {
        $this->enable([
            RecordingService::class.'@store',
            PayloadOnlyHandler::class,
        ]);

        $this->failLogin();

        $this->assertCount(2, self::$captured);
    }

    // -----------------------------------------------------------------------
    // Coverage across operations
    // -----------------------------------------------------------------------

    public function test_a_failed_redirect_is_captured(): void
    {
        // No callback ever arrives for a redirect that fails to build, so this
        // leg is invisible unless it is captured here.
        $this->enable([RecordingService::class.'@store']);

        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('redirectUrl')->andThrow(new RuntimeException('provider is down'));
        $this->swapAdapter($adapter);

        try {
            $this->app->make(FederatedAuthBroker::class)->redirectUrl('google', $this->context());
            $this->fail('The adapter exception must still reach the caller.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertCount(1, self::$captured);
        $this->assertSame(
            FederatedAuthError::OPERATION_REDIRECT,
            self::$captured[0]['operation']
        );
        $this->assertSame(500, self::$captured[0]['status_code']);
    }

    // -----------------------------------------------------------------------
    // Filtering
    // -----------------------------------------------------------------------

    public function test_ignored_exceptions_are_not_captured(): void
    {
        $this->enable([RecordingService::class.'@store']);
        config()->set('federated-auth.error_reporting.ignore_exceptions', [
            InvalidProviderTokenException::class,
        ]);

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }

    public function test_only_exceptions_narrows_capture(): void
    {
        $this->enable([RecordingService::class.'@store']);
        config()->set('federated-auth.error_reporting.only_exceptions', [
            InvalidOAuthStateException::class,
        ]);

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }

    public function test_payload_only_restricts_the_row_to_existing_columns(): void
    {
        // A model with a strict $fillable throws on an unknown key.
        $this->enable([RecordingService::class.'@store']);
        config()->set('federated-auth.error_reporting.payload.only', [
            'description', 'error', 'status_code',
        ]);

        $this->failLogin();

        $this->assertSame(
            ['description', 'error', 'status_code'],
            array_keys(self::$captured[0])
        );
    }

    // -----------------------------------------------------------------------
    // Security: an error table must not become a credential store
    // -----------------------------------------------------------------------

    public function test_the_payload_never_contains_the_authorization_code_or_state(): void
    {
        $this->enable([RecordingService::class.'@store']);

        $request = Request::create(
            '/auth/google/callback?code=4/0AdeuSecretCode&state=the-real-state',
            'GET'
        );
        $request->headers->set('Authorization', 'Bearer ya29.super-secret-access-token');
        $request->headers->set('Cookie', 'session=abcdef123456');

        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('userFromToken')->andThrow(new InvalidProviderTokenException(
            'Provider rejected https://oauth2.googleapis.com/token?code=4/0AdeuSecretCode&client_secret=hunter2'
        ));
        $this->swapAdapter($adapter);

        try {
            $this->app->make(FederatedAuthBroker::class)
                ->loginFromToken('google', 'forged', $this->context($request));
        } catch (InvalidProviderTokenException) {
            // Expected.
        }

        $serialized = json_encode(self::$captured[0]);

        $this->assertStringNotContainsString('4/0AdeuSecretCode', $serialized);
        $this->assertStringNotContainsString('the-real-state', $serialized);
        $this->assertStringNotContainsString('ya29.super-secret-access-token', $serialized);
        $this->assertStringNotContainsString('hunter2', $serialized);
        $this->assertStringNotContainsString('session=abcdef123456', $serialized);

        // The correlation digest survives, which is what makes the row useful.
        $this->assertNotNull(self::$captured[0]['state_digest']);
        $this->assertNotSame('the-real-state', self::$captured[0]['state_digest']);
    }

    public function test_a_bare_jwt_in_a_message_is_redacted(): void
    {
        $this->enable([RecordingService::class.'@store']);

        $jwt = 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjEyMyJ9.eyJzdWIiOiIxMDkyIn0.c2lnbmF0dXJl';
        $this->failLogin(new InvalidProviderTokenException("Could not verify $jwt"));

        $this->assertStringNotContainsString($jwt, self::$captured[0]['error']);
        $this->assertStringContainsString(SensitiveDataScrubber::REDACTED, self::$captured[0]['error']);
    }

    public function test_json_columns_are_never_null(): void
    {
        // An existing error_logs table commonly declares `parameters` and
        // `request` NOT NULL, so "nothing captured" must still be insertable.
        $this->enable([RecordingService::class.'@store']);
        config()->set('federated-auth.error_reporting.payload.include_request', false);
        config()->set('federated-auth.error_reporting.payload.include_headers', false);

        $this->failLogin();

        $this->assertSame('{}', self::$captured[0]['parameters']);
        $this->assertSame('{}', self::$captured[0]['request']);
        $this->assertSame('{}', self::$captured[0]['headers']);
    }

    public function test_email_is_withheld_unless_explicitly_enabled(): void
    {
        $this->enable([RecordingService::class.'@store']);

        $this->failLogin();

        $this->assertNull(self::$captured[0]['email']);
    }

    // -----------------------------------------------------------------------
    // Robustness: capture must never replace the real error
    // -----------------------------------------------------------------------

    public function test_a_throwing_handler_does_not_break_authentication(): void
    {
        $this->enable([ExplodingReporter::class]);

        $adapter = Mockery::mock(IdentityProviderAdapterInterface::class);
        $adapter->shouldReceive('provider')->andReturn('google');
        $adapter->shouldReceive('userFromToken')
            ->andThrow(new InvalidProviderTokenException('bad token'));
        $this->swapAdapter($adapter);

        // The caller must still see the authentication failure, not a database
        // error raised by the code that was trying to record it.
        $this->expectException(InvalidProviderTokenException::class);

        $this->app->make(FederatedAuthBroker::class)
            ->loginFromToken('google', 'forged-token', $this->context());
    }

    public function test_one_broken_handler_does_not_stop_the_others(): void
    {
        $this->enable([ExplodingReporter::class, RecordingService::class.'@store']);

        $this->failLogin();

        $this->assertCount(1, self::$captured);
    }

    public function test_an_unknown_handler_class_is_survived(): void
    {
        $this->enable(['App\\Does\\Not\\Exist']);

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }

    public function test_binding_the_null_reporter_disables_capture_entirely(): void
    {
        $this->enable([RecordingService::class.'@store']);
        $this->app->bind(ErrorReporterInterface::class, NullErrorReporter::class);

        $this->failLogin();

        $this->assertSame([], self::$captured);
    }
}

// ---------------------------------------------------------------------------
// Handler doubles, one per supported shape.
// ---------------------------------------------------------------------------

class RecordingReporter implements ErrorReporterInterface
{
    public function report(FederatedAuthError $error): void
    {
        ErrorReportingTest::$capturedErrors[] = $error;
    }
}

/**
 * Mirrors the trait set of a real host logging job.
 */
class RecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $errorData) {}

    public function handle(): void {}
}

/**
 * ShouldQueue without the Queueable trait: legal, but has no onQueue().
 */
class BareQueuedJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public readonly array $errorData) {}

    public function handle(): void {}
}

class RecordingService
{
    public function store(array $payload, FederatedAuthError $error): void
    {
        ErrorReportingTest::$captured[] = $payload;
    }
}

class PayloadOnlyHandler
{
    public function handle(array $payload): void
    {
        ErrorReportingTest::$captured[] = $payload;
    }
}

class ExplodingReporter implements ErrorReporterInterface
{
    public function report(FederatedAuthError $error): void
    {
        throw new RuntimeException('the error table is gone');
    }
}
