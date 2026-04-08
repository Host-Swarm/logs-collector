<?php

declare(strict_types=1);

use App\Http\Controllers\ContainerExecController;
use App\Http\Controllers\ContainerLogsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StackController;
use Illuminate\Support\Facades\Route;

Route::get('/stacks', [StackController::class, 'index']);
Route::get('/stacks/{stack}', [StackController::class, 'show']);
Route::get('/health', HealthController::class);
Route::get('/containers/{containerId}/stream', ContainerLogsController::class);
Route::get('/containers/{containerId}/logs', ContainerLogsController::class);
Route::get('/containers/{containerId}/exec', [ContainerExecController::class, 'stream']);
Route::post('/containers/exec/{session}/input', [ContainerExecController::class, 'input']);
Route::post('/containers/exec/{session}/resize', [ContainerExecController::class, 'resize']);
