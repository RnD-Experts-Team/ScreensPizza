<?php

use App\Http\Controllers\StationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\LivekitAdminController;
use App\Http\Controllers\LivekitParticipantController;
use App\Http\Controllers\LivekitTokenController;
use App\Http\Controllers\StationMediaController;
use App\Http\Controllers\ScreenUsageController;
use App\Http\Controllers\ScreenUsageReportController;
use App\Http\Controllers\ReportDatasetController;
use App\Reports\ReportDatasets;
use Illuminate\Support\Facades\Route;

// Rate limits (AppServiceProvider): `throttle:api` covers every route, ahead of
// the pizzasys call. The two public routes and uploads add their own tier on
// top. The usage heartbeat and upload chunks REPLACE it with their own tier,
// since the general limit is lower and would cap them.

// The report dataset contract (the same in every service). One literal query route per
// dataset (never a {key} parameter), so each dataset has its own pizzasys auth rule.
// At the API root; the body's `stores` are store codes. On the general limit.
Route::get('report-datasets', [ReportDatasetController::class, 'catalog'])->middleware(['throttle:api', 'auth.token.store']);
foreach (ReportDatasets::DATASETS as $dataset) {
    Route::post('report-datasets/'.$dataset::KEY.'/query', [ReportDatasetController::class, 'query'])
        ->defaults('dataset', $dataset::KEY)->middleware(['throttle:api', 'auth.token.store']);
}

// Screen Project monitoring across stores (stores[] = store numbers, checked by the auth server).
Route::middleware(['throttle:api', 'auth.token.store'])->group(function () {
    Route::get('/usage/report', [ScreenUsageReportController::class, 'report']);
    Route::get('/usage/timeline', [ScreenUsageReportController::class, 'timeline']);
    // The store dashboards' slice of it (DSPR V1, Business Reports), under their report permission.
    Route::get('/usage/boots-on-the-ground', [ScreenUsageReportController::class, 'bootsOnTheGround']);
});
// The same numbers as CSV for Excel, per manager (/usage/export) or cut by the station in the main tile
// (/usage/export/stations): ?start_date=&end_date=[&stores=a,b][&tz=], no stores = every store.
// A shared secret (X-Secret-Key), not a user token: its own rate limit and no pizzasys auth rule.
Route::get('/usage/export', [ScreenUsageReportController::class, 'export'])->middleware(['throttle:service-secret', 'auth.secret.key']);
Route::get('/usage/export/stations', [ScreenUsageReportController::class, 'exportStations'])->middleware(['throttle:service-secret', 'auth.secret.key']);

Route::prefix('{storeId}')->middleware(['throttle:api', 'auth.token.store'])
    ->group(function () {
        Route::get('/stations', [StationController::class, 'index'])->withoutMiddleware('auth.token.store')->middleware('throttle:api-public');
        Route::post('/stations', [StationController::class, 'store']);
        Route::post('/stations/password', [StoreController::class, 'setStationPassword']);
        Route::delete('/stations/{station}', [StationController::class, 'destroy']);
        Route::get('/stations/{station}/media', [StationMediaController::class, 'index']);
        Route::post('/stations/{station}/media/uploads', [StationMediaController::class, 'initUpload'])->middleware('throttle:api-upload');
        Route::post('/stations/{station}/media/uploads/{upload}/chunks/{chunkIndex}', [StationMediaController::class, 'uploadChunk'])->withoutMiddleware('throttle:api')->middleware('throttle:api-upload-chunk');
        Route::post('/stations/{station}/media/uploads/{upload}/complete', [StationMediaController::class, 'completeUpload'])->middleware('throttle:api-upload');
        Route::post('/stations/{station}/media/uploads/complete-bulk', [StationMediaController::class, 'completeUploadsBulk'])->middleware('throttle:api-upload');
        Route::post('/stations/{station}/media/{media}/primary', [StationMediaController::class, 'setPrimary']);
        Route::delete('/stations/{station}/media/bulk', [StationMediaController::class, 'bulkDelete']);
        // Takes the store's station password, no bearer token.
        Route::post('/tokens/stations/{station}', [LivekitTokenController::class, 'station'])->withoutMiddleware('auth.token.store')->middleware('throttle:station-token');
        Route::post('/tokens/supervisor', [LivekitTokenController::class, 'supervisor']);
        Route::post('/tokens/observer', [LivekitTokenController::class, 'observer']);
        Route::post('/usage/segments', [ScreenUsageController::class, 'store'])->withoutMiddleware('throttle:api')->middleware('throttle:api-poll');
    });

// // Participant listing/removal
// Route::get('/stations/{room}/participants', [LivekitParticipantController::class, 'list']);
// Route::delete('/stations/{room}/participants/{identity}', [LivekitParticipantController::class, 'remove']);

// Token generation

// Route::post('/tokens/custom', [LivekitTokenController::class, 'custom']);
// Route::post('/tokens/parse', [LivekitTokenController::class, 'parse']);
// Route::get('/tokens', [LivekitTokenController::class, 'index']);
// Route::delete('/tokens/{id}', [LivekitTokenController::class, 'revoke']);

// // LiveKit rooms
// Route::get('/livekit/rooms', [LivekitAdminController::class, 'listRooms']);
// Route::post('/livekit/rooms', [LivekitAdminController::class, 'createRoom']);
// Route::delete('/livekit/rooms/{room}', [LivekitAdminController::class, 'deleteRoom']);
// Route::patch('/livekit/rooms/{room}/metadata', [LivekitAdminController::class, 'updateRoomMetadata']);

// LiveKit participants
// Route::get('/livekit/rooms/{room}/participants', [LivekitAdminController::class, 'listParticipants']);
// Route::get('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'getParticipant']);
// Route::delete('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'removeParticipant']);
// Route::post('/livekit/rooms/{room}/participants/{identity}/forward', [LivekitAdminController::class, 'forwardParticipant']);
// Route::post('/livekit/rooms/{room}/participants/{identity}/move', [LivekitAdminController::class, 'moveParticipant']);
// Route::post('/livekit/rooms/{room}/participants/{identity}/mute-track', [LivekitAdminController::class, 'muteTrack']);
// Route::patch('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'updateParticipant']);
// Route::post('/livekit/rooms/{room}/participants/{identity}/subscriptions', [LivekitAdminController::class, 'updateSubscriptions']);
// Route::post('/livekit/rooms/{room}/send-data', [LivekitAdminController::class, 'sendData']);