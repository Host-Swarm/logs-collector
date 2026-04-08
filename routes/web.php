<?php

declare(strict_types=1);

use App\Http\Controllers\ExecTerminalController;
use App\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.all');
Route::get('/logs/{stack}', [LogViewerController::class, 'stack'])->name('logs.stack');
Route::get('/logs/{stack}/{service}', [LogViewerController::class, 'service'])->name('logs.service');
Route::get('/logs/{stack}/{service}/{containerId}', [LogViewerController::class, 'container'])->name('logs.container');
Route::get('/exec/{containerId}', ExecTerminalController::class)->name('exec.terminal');
