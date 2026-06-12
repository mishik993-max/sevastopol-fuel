<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\PushWatchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\VisitController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StationClosureController;
use App\Http\Controllers\Api\StationCorrectionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/stations', [StationController::class, 'store'])->middleware('throttle:5,60');
    Route::get('/stations/nearby', [StationController::class, 'nearby']);
    Route::get('/stations/{station}', [StationController::class, 'show']);

    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:reports-store');
    Route::post('/stations/{station}/confirm', [ReportController::class, 'confirm'])->middleware('throttle:reports-confirm');
    Route::post('/stations/{station}/close', [StationClosureController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/stations/{station}/corrections', [StationCorrectionController::class, 'store'])->middleware('throttle:10,60');
    Route::post('/stations/{station}/corrections/{correction}/confirm', [StationCorrectionController::class, 'confirm'])->middleware('throttle:30,1');

    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/faq', [FaqController::class, 'index']);

    Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,60');
    Route::post('/visit', [VisitController::class, 'store'])->middleware('throttle:30,1');

    Route::get('/push/vapid-public-key', [PushController::class, 'vapidPublicKey']);
    Route::post('/push/subscribe', [PushController::class, 'subscribe']);
    Route::delete('/push/unsubscribe', [PushController::class, 'unsubscribe']);
    Route::put('/push/watches', [PushWatchController::class, 'sync'])->middleware('throttle:30,1');
    Route::delete('/push/watches', [PushWatchController::class, 'destroy'])->middleware('throttle:30,1');
});

Route::post('/admin/login', [AdminController::class, 'login']);

Route::middleware(['admin'])->prefix('admin')->group(function () {
    Route::get('/summary', [AdminController::class, 'summary']);
    Route::get('/analytics', [AdminController::class, 'analytics']);
    Route::get('/system', [AdminController::class, 'system']);
    Route::get('/ai-chat/status', [AdminController::class, 'aiChatStatus']);
    Route::post('/ai-chat/parse', [AdminController::class, 'aiChatParse']);
    Route::get('/ai-chat/queue', [AdminController::class, 'aiChatQueue']);
    Route::delete('/ai-chat/queue/{queueItem}', [AdminController::class, 'aiChatQueueDestroy']);
    Route::post('/ai-chat/apply', [AdminController::class, 'aiChatApply']);
    Route::get('/corrections', [AdminController::class, 'corrections']);
    Route::post('/corrections/{correction}/apply', [AdminController::class, 'applyCorrection']);
    Route::post('/corrections/{correction}/reject', [AdminController::class, 'rejectCorrection']);
    Route::get('/feedback', [AdminController::class, 'feedback']);
    Route::patch('/feedback/{feedback}', [AdminController::class, 'updateFeedback']);
    Route::get('/settings', [AdminController::class, 'settings']);
    Route::patch('/settings', [AdminController::class, 'updateSettings']);
    Route::get('/reports', [AdminController::class, 'reports']);
    Route::post('/reports/{report}/hide', [AdminController::class, 'hideReport']);
    Route::post('/reports/{report}/unhide', [AdminController::class, 'unhideReport']);
    Route::delete('/reports/{report}', [AdminController::class, 'destroyReport']);
    Route::get('/osm-import/preview', [AdminController::class, 'osmImportPreview']);
    Route::post('/osm-import/run', [AdminController::class, 'osmImportRun']);
    Route::get('/sevtech/preview', [AdminController::class, 'sevtechPreview']);
    Route::post('/sevtech/sync', [AdminController::class, 'sevtechSync']);
    Route::get('/push/status', [AdminController::class, 'pushStatus']);
    Route::post('/push/send', [AdminController::class, 'sendPush']);
    Route::get('/faq', [AdminController::class, 'faq']);
    Route::post('/faq', [AdminController::class, 'storeFaq']);
    Route::patch('/faq/reorder', [AdminController::class, 'reorderFaq']);
    Route::patch('/faq/{faq}', [AdminController::class, 'updateFaq']);
    Route::delete('/faq/{faq}', [AdminController::class, 'destroyFaq']);
});
