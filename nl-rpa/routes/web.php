<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InputController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\StopController;
use Illuminate\Support\Facades\Route;

$hash = 'sha256:[0-9a-f]{64}';
$run = 'RUN-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}';

Route::get('/login', [AuthController::class, 'show']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('nlrpa.token')->group(function () use ($hash, $run): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/', [HomeController::class, 'index']);
    Route::post('/inputs', [InputController::class, 'store']);
    Route::post('/plans', [PlanController::class, 'create']);
    Route::get('/plans/{hash}', [PlanController::class, 'show'])->where('hash', $hash);
    Route::post('/plans/{hash}/approve-plan', [PlanController::class, 'approvePlan'])->where('hash', $hash);
    Route::post('/plans/{hash}/dry-run', [PlanController::class, 'dryRun'])->where('hash', $hash);
    Route::post('/plans/{hash}/approve-final', [PlanController::class, 'approveFinal'])->where('hash', $hash);
    Route::get('/plans/{hash}/preview', [PlanController::class, 'preview'])->where('hash', $hash);
    Route::post('/plans/{hash}/register', [PlanController::class, 'register'])->where('hash', $hash);
    Route::get('/runs/{runId}', [RunController::class, 'show'])->where('runId', $run);
    Route::post('/runs/{runId}/start', [RunController::class, 'start'])->where('runId', $run);
    Route::post('/runs/{runId}/confirm-first', [RunController::class, 'confirmFirst'])->where('runId', $run);
    Route::post('/runs/{runId}/resolve', [RunController::class, 'resolve'])->where('runId', $run);
    Route::post('/stop', [StopController::class, 'stop']);
    Route::post('/stop/clear', [StopController::class, 'clear']);
});
