<?php

use App\Http\Controllers\StationController;
use App\Http\Controllers\LivekitAdminController;
use App\Http\Controllers\LivekitParticipantController;
use App\Http\Controllers\LivekitTokenController;
use Illuminate\Support\Facades\Route;

Route::get('/stations', [StationController::class, 'index']);
Route::post('/stations', [StationController::class, 'store']);
Route::delete('/stations/{station}', [StationController::class, 'destroy']);

// Participant listing/removal
Route::get('/stations/{room}/participants', [LivekitParticipantController::class, 'list']);
Route::delete('/stations/{room}/participants/{identity}', [LivekitParticipantController::class, 'remove']);

// Token generation
Route::post('/tokens/station', [LivekitTokenController::class, 'station']);
Route::post('/tokens/supervisor', [LivekitTokenController::class, 'supervisor']);
Route::post('/tokens/custom', [LivekitTokenController::class, 'custom']);
Route::post('/tokens/parse', [LivekitTokenController::class, 'parse']);
Route::get('/tokens', [LivekitTokenController::class, 'index']);
Route::delete('/tokens/{id}', [LivekitTokenController::class, 'revoke']);

// LiveKit rooms
Route::get('/livekit/rooms', [LivekitAdminController::class, 'listRooms']);
Route::post('/livekit/rooms', [LivekitAdminController::class, 'createRoom']);
Route::delete('/livekit/rooms/{room}', [LivekitAdminController::class, 'deleteRoom']);
Route::patch('/livekit/rooms/{room}/metadata', [LivekitAdminController::class, 'updateRoomMetadata']);

// LiveKit participants
Route::get('/livekit/rooms/{room}/participants', [LivekitAdminController::class, 'listParticipants']);
Route::get('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'getParticipant']);
Route::delete('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'removeParticipant']);
Route::post('/livekit/rooms/{room}/participants/{identity}/forward', [LivekitAdminController::class, 'forwardParticipant']);
Route::post('/livekit/rooms/{room}/participants/{identity}/move', [LivekitAdminController::class, 'moveParticipant']);
Route::post('/livekit/rooms/{room}/participants/{identity}/mute-track', [LivekitAdminController::class, 'muteTrack']);
Route::patch('/livekit/rooms/{room}/participants/{identity}', [LivekitAdminController::class, 'updateParticipant']);
Route::post('/livekit/rooms/{room}/participants/{identity}/subscriptions', [LivekitAdminController::class, 'updateSubscriptions']);
Route::post('/livekit/rooms/{room}/send-data', [LivekitAdminController::class, 'sendData']);