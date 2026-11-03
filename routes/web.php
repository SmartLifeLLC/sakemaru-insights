<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

/*
|--------------------------------------------------------------------------
| 運用監視: 収集器からの取込 API（HMAC 認証。セッション・CSRF は使わない）
|--------------------------------------------------------------------------
*/
Route::prefix('internal/monitor')
    ->middleware([\App\Http\Middleware\VerifyMonitorCollectorSignature::class])
    ->group(function () {
        Route::post('events', [\App\Http\Controllers\Monitoring\IngestController::class, 'events'])->name('monitor.ingest.events');
        Route::post('heartbeat', [\App\Http\Controllers\Monitoring\IngestController::class, 'heartbeat'])->name('monitor.ingest.heartbeat');
    });
Route::post('internal/monitor/metrics', [\App\Http\Controllers\Monitoring\IngestController::class, 'metrics'])
    ->middleware([\App\Http\Middleware\VerifyMonitorNodeSignature::class])->name('monitor.ingest.metrics');
// Twilio（電話通知）からのコールバック。X-Twilio-Signature を各アクションで検証する
Route::prefix('internal/monitor/voice/{token}')->middleware('throttle:60,1')->group(function () {
    Route::post('/', [\App\Http\Controllers\Monitoring\VoiceController::class, 'twiml'])->name('monitor.voice.twiml');
    Route::post('gather', [\App\Http\Controllers\Monitoring\VoiceController::class, 'gather'])->name('monitor.voice.gather');
    Route::post('status', [\App\Http\Controllers\Monitoring\VoiceController::class, 'status'])->name('monitor.voice.status');
});
