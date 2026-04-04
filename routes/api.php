<?php

declare(strict_types=1);

use App\Http\Controllers\ContainerExecController;
use App\Http\Controllers\ContainerLogsController;
use App\Http\Controllers\StackController;
use App\Http\Middleware\PassportOneTimeMiddleware;
use App\Http\Middleware\ServerSecretMiddleware;
use Illuminate\Support\Facades\Route;

// Stack and service listing — authenticated with server secret.
Route::middleware(ServerSecretMiddleware::class)->group(function (): void {
    Route::get('/stacks', [StackController::class, 'index']);
    Route::get('/stacks/{stack}', [StackController::class, 'show']);
});

// Container log streaming and exec — authenticated with one-time Passport token.
Route::middleware(PassportOneTimeMiddleware::class)->group(function (): void {
    Route::get('/containers/{containerId}/logs', ContainerLogsController::class);
    Route::get('/containers/{containerId}/exec', ContainerExecController::class);
});
