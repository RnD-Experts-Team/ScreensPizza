<?php

use App\Http\Controllers\StationController;
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