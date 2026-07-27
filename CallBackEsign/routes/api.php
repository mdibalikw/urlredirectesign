<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/callback/mekari-esign', [\App\Http\Controllers\MekariCallbackController::class, 'handleCallback'])
    ->middleware(\App\Http\Middleware\VerifyMekariToken::class);
