<?php

use App\Http\Controllers\TestApiController;
use App\Http\Controllers\LaboratoryEndpointController;
use Illuminate\Support\Facades\Route;

Route::get('/test', [TestApiController::class, 'test']);
Route::apiResource('test-items', TestApiController::class);

Route::get('/endpoint/{id}', [LaboratoryEndpointController::class, 'show']);

// Rutas públicas para testing del laboratorio
Route::get('/laboratory/test', [LaboratoryEndpointController::class, 'test']);
Route::get('/laboratory/create-test', [LaboratoryEndpointController::class, 'createTest']);
Route::apiResource('laboratory/notifications', LaboratoryEndpointController::class);