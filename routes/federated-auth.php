<?php

use Illuminate\Support\Facades\Route;
use Ronu\LaravelFederatedAuth\Http\Controllers\FederatedAuthController;

$prefix = config('federated-auth.routes.prefix', 'api/auth/federated');
$middleware = config('federated-auth.routes.middleware', ['api']);
$protectedMiddleware = config('federated-auth.routes.protected_middleware', ['api', 'auth:api']);
$namePrefix = config('federated-auth.routes.name_prefix', 'federated-auth.');

Route::prefix($prefix)->middleware($middleware)->name($namePrefix)->group(function () {
    Route::get('providers', [FederatedAuthController::class, 'providers'])->name('providers');
    Route::get('{provider}/redirect', [FederatedAuthController::class, 'redirect'])->name('redirect');
    Route::match(['GET', 'POST'], '{provider}/callback', [FederatedAuthController::class, 'callback'])->name('callback');
    Route::post('{provider}/token', [FederatedAuthController::class, 'token'])->name('token');
});

Route::prefix($prefix)->middleware($protectedMiddleware)->name($namePrefix)->group(function () {
    Route::post('{provider}/link/token', [FederatedAuthController::class, 'linkToken'])->name('link.token');
    Route::delete('{provider}/unlink', [FederatedAuthController::class, 'unlink'])->name('unlink');
});
