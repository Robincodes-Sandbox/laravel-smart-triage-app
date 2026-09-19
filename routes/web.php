<?php

use App\Http\Controllers\DemoLockController;
use App\Http\Controllers\RepairsController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::get('/unlock', [DemoLockController::class, 'show'])->name('demo.unlock');
Route::post('/unlock', [DemoLockController::class, 'unlock'])->name('demo.unlock.submit');

Route::middleware('demo.lock')->group(function () {
    Route::get('/', [RepairsController::class, 'index'])->name('repairs');

    // The second worked example: a firehose of short records sharing one context.
    Route::get('/logs', [TriageController::class, 'index'])->name('triage');

    // Re-running a triage costs real tokens, so it is throttled as well as locked.
    Route::middleware('throttle:demo-triage')->group(function () {
        Route::post('/triage', [RepairsController::class, 'triage'])->name('repairs.triage');
        Route::post('/logs/classify', [TriageController::class, 'classify'])->name('triage.classify');
    });
});
