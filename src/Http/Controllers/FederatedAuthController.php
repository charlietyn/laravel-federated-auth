<?php

namespace Ronu\LaravelFederatedAuth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ronu\LaravelFederatedAuth\Contracts\AuthResponseFormatterInterface;
use Ronu\LaravelFederatedAuth\Contracts\IdentityProviderRegistryInterface;
use Ronu\LaravelFederatedAuth\DTO\AuthContext;
use Ronu\LaravelFederatedAuth\DTO\AuthResult;
use Ronu\LaravelFederatedAuth\Services\FederatedAuthBroker;

class FederatedAuthController extends Controller
{
    public function __construct(
        private readonly FederatedAuthBroker $broker,
        private readonly AuthResponseFormatterInterface $formatter,
    ) {}

    public function providers(): JsonResponse
    {
        $providers = collect(config('federated-auth.providers', []))
            ->map(fn (array $provider, string $key): array => [
                'name' => $key,
                'enabled' => (bool) ($provider['enabled'] ?? false),
                'driver' => $provider['driver'] ?? null,
                'auto_provision' => (bool) ($provider['auto_provision'] ?? false),
                'login_url' => url(config('federated-auth.routes.prefix', 'api/auth/federated').'/'.$key.'/redirect'),
            ])
            ->values();

        return response()->json(['providers' => $providers]);
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        return redirect()->away($this->broker->redirectUrl($provider, AuthContext::fromRequest($provider, $request)));
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        $context = AuthContext::fromRequest($provider, $request);
        $result = $this->broker->loginFromCallback($provider, $context);

        return $this->response($result, $context);
    }

    public function token(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'required_without:id_token'],
            'id_token' => ['nullable', 'string', 'required_without:access_token'],
            'user_type' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'string'],
            'channel' => ['nullable', 'string'],
        ]);

        $context = AuthContext::fromRequest($provider, $request);
        $token = $validated['id_token'] ?? $validated['access_token'];
        $result = $this->broker->loginFromToken($provider, $token, $context);

        return $this->response($result, $context);
    }

    public function linkToken(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'required_without:id_token'],
            'id_token' => ['nullable', 'string', 'required_without:access_token'],
        ]);

        $context = AuthContext::fromRequest($provider, $request);
        $token = $validated['id_token'] ?? $validated['access_token'];
        $identity = app(IdentityProviderRegistryInterface::class)->adapterFor($provider)->userFromToken($token, $context);
        $result = $this->broker->linkIdentity($request->user(), $identity, $context);

        return $this->response($result, $context);
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $this->broker->unlink($request->user(), $provider, AuthContext::fromRequest($provider, $request));

        return response()->json([
            'success' => true,
            'message' => 'External identity unlinked successfully.',
        ]);
    }

    private function response(AuthResult $result, AuthContext $context): JsonResponse
    {
        return response()->json($this->formatter->format($result, $context));
    }
}
