<?php

use App\Http\Controllers\StationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\LivekitAdminController;
use App\Http\Controllers\LivekitParticipantController;
use App\Http\Controllers\LivekitTokenController;
use App\Http\Controllers\StationMediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('{StoreId}')->middleware('auth.token.store')
    ->group(function () {
        Route::get('/stations', [StationController::class, 'index'])->withoutMiddleware('auth.token.store');
        Route::post('/stations', [StationController::class, 'store']);
        Route::post('/stations/password', [StoreController::class, 'setStationPassword']);
        Route::delete('/stations/{station}', [StationController::class, 'destroy']);
        Route::get('/stations/{station}/media', [StationMediaController::class, 'index']);
        Route::post('/stations/{station}/media/uploads', [StationMediaController::class, 'initUpload']);
        Route::post('/stations/{station}/media/uploads/{upload}/chunks/{chunkIndex}', [StationMediaController::class, 'uploadChunk']);
        Route::post('/stations/{station}/media/uploads/{upload}/complete', [StationMediaController::class, 'completeUpload']);
        Route::post('/stations/{station}/media/uploads/complete-bulk', [StationMediaController::class, 'completeUploadsBulk']);
        Route::post('/stations/{station}/media/{media}/primary', [StationMediaController::class, 'setPrimary']);
        Route::delete('/stations/{station}/media/bulk', [StationMediaController::class, 'bulkDelete']);
        Route::post('/tokens/stations/{station}', [LivekitTokenController::class, 'station'])->withoutMiddleware('auth.token.store');
        Route::post('/tokens/supervisor', [LivekitTokenController::class, 'supervisor']);
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