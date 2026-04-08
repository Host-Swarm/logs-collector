<?php

declare(strict_types=1);

use App\Http\Controllers\ContainerExecController;
use App\Http\Controllers\ContainerLogsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogViewerController;
use App\Http\Controllers\StackController;
use App\Http\Middleware\PassportOneTimeMiddleware;
use App\Http\Middleware\ServerSecretMiddleware;
use Illuminate\Support\Facades\Route;

// Log viewer — public HTML pages (auth handled client-side with server secret).
Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.all');
Route::get('/logs/{stack}', [LogViewerController::class, 'stack'])->name('logs.stack');
Route::get('/logs/{stack}/{service}', [LogViewerController::class, 'service'])->name('logs.service');
Route::get('/logs/{stack}/{service}/{containerId}', [LogViewerController::class, 'container'])->name('logs.container');

// Stack listing, health check, and web-viewer log streaming — authenticated with server secret.
Route::middleware(ServerSecretMiddleware::class)->group(function (): void {
    Route::get('/stacks', [StackController::class, 'index']);
    Route::get('/stacks/{stack}', [StackController::class, 'show']);
    Route::get('/health', HealthController::class);

    // Log streaming for the web viewer — same controller, server-secret auth.
    Route::get('/containers/{containerId}/stream', ContainerLogsController::class);
});

// Container log streaming and exec — authenticated with one-time Passport token.
Route::middleware(PassportOneTimeMiddleware::class)->group(function (): void {
    Route::get('/containers/{containerId}/logs', ContainerLogsController::class);
    Route::get('/containers/{containerId}/exec', ContainerExecController::class);
});
